<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/*
 * Phase 6B hardening cover: audit findings M-2 (login enumeration), M-3
 * (unthrottled activation GET) and L-4 (token flashed as old input).
 *
 * Tokens are never passed to expect(), so a failing assertion cannot print a
 * live invitation token into the test output.
 */

function hardeningInvitee(array $overrides = []): User
{
    return User::factory()->unverified()->create(array_merge([
        'role' => 'nurse',
        'account_status' => 'inactive',
        'password' => Hash::make('placeholder-never-shared'),
    ], $overrides));
}

// --- 1. activation GET is rate limited --------------------------------------

test('the activation GET endpoint is rate limited', function () {
    $user = hardeningInvitee();
    $token = Password::broker('staff_invitations')->createToken($user);

    $url = route('staff.activate', ['token' => $token, 'email' => $user->email]);

    foreach (range(1, 6) as $ignored) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});

test('the activation GET limiter also covers invalid tokens', function () {
    $user = hardeningInvitee();

    $url = route('staff.activate', ['token' => 'not-a-real-token', 'email' => $user->email]);

    foreach (range(1, 6) as $ignored) {
        $this->get($url)->assertRedirect(route('login'));
    }

    // Without a limiter this endpoint could be probed indefinitely for the
    // timing difference between an eligible invitee and an unknown address.
    $this->get($url)->assertStatus(429);
});

// --- 2. login no longer discloses account status ----------------------------

test('a wrong password gives the same error whether or not the account is active', function () {
    $active = User::factory()->create(['account_status' => 'active']);
    $inactive = hardeningInvitee();

    $activeResponse = $this->post('/login', [
        'email' => $active->email,
        'password' => 'definitely-the-wrong-password',
    ]);

    $this->flushSession();

    $inactiveResponse = $this->post('/login', [
        'email' => $inactive->email,
        'password' => 'definitely-the-wrong-password',
    ]);

    $activeError = $activeResponse->getSession()->get('errors')->get('email');
    $inactiveError = $inactiveResponse->getSession()->get('errors')->get('email');

    expect($inactiveError)->toBe($activeError)
        ->and($activeError[0])->toBe(trans('auth.failed'));

    $this->assertGuest();
});

test('an unknown address and a pending staff address are indistinguishable', function () {
    $pending = hardeningInvitee();

    $unknownResponse = $this->post('/login', [
        'email' => 'nobody-at-all@clsu.edu.ph',
        'password' => 'some-password',
    ]);

    $this->flushSession();

    $pendingResponse = $this->post('/login', [
        'email' => $pending->email,
        'password' => 'some-password',
    ]);

    expect($pendingResponse->getSession()->get('errors')->get('email'))
        ->toBe($unknownResponse->getSession()->get('errors')->get('email'));
});

test('probing a pending account now consumes the rate limiter', function () {
    $pending = hardeningInvitee();

    // Correct credentials against an inactive account: previously this returned
    // before RateLimiter::hit(), so it could be repeated without limit.
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', [
            'email' => $pending->email,
            'password' => 'placeholder-never-shared',
        ])->assertSessionHasErrors('email');

        $this->flushSession();
    }

    $this->post('/login', [
        'email' => $pending->email,
        'password' => 'placeholder-never-shared',
    ])->assertSessionHasErrors('email');

    expect($this->app['session.store']->get('errors')->get('email')[0])
        ->toContain('Too many login attempts');
});

test('an inactive account is still refused and left signed out', function () {
    $pending = hardeningInvitee();

    $this->post('/login', [
        'email' => $pending->email,
        'password' => 'placeholder-never-shared',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the owner of an inactive account is still told why they cannot sign in', function () {
    $pending = hardeningInvitee();

    $response = $this->post('/login', [
        'email' => $pending->email,
        'password' => 'placeholder-never-shared',
    ]);

    // Disclosed only to someone who proved the password, which is why it is no
    // longer an enumeration vector.
    expect($response->getSession()->get('errors')->get('email')[0])
        ->toBe('This account is inactive.');
});

test('an active account can still log in normally', function () {
    $user = User::factory()->create([
        'account_status' => 'active',
        'password' => Hash::make('correct-horse-battery-1'),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-1',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

// --- 5. the token is never flashed as old input -----------------------------

test('a rejected activation does not flash the invitation token as old input', function () {
    $user = hardeningInvitee();
    $token = Password::broker('staff_invitations')->createToken($user);

    // Fails on the password rules, so the request round-trips as old input.
    $this->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $old = session('_old_input', []);

    expect(array_key_exists('token', $old))->toBeFalse()
        ->and(array_key_exists('password', $old))->toBeFalse()
        ->and(array_key_exists('password_confirmation', $old))->toBeFalse()
        // the harmless fields still round-trip, so the form is still usable
        ->and($old['email'] ?? null)->toBe($user->email);
});

test('a rejected password reset does not flash the reset token either', function () {
    $user = User::factory()->create(['account_status' => 'active']);
    $token = Password::broker('users')->createToken($user);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(array_key_exists('token', session('_old_input', [])))->toBeFalse();
});
