<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

function invitedStaff(array $attributes = []): User
{
    return User::factory()->unverified()->create(array_merge([
        'role' => 'nurse',
        'account_status' => 'inactive',
        'password' => Hash::make('placeholder-never-shared'),
    ], $attributes));
}

function inviteToken(User $user): string
{
    return Password::broker('staff_invitations')->createToken($user);
}

function activate(User $user, string $token, array $overrides = []): TestResponse
{
    return test()->post(route('staff.activate.store'), array_merge([
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password-1',
        'password_confirmation' => 'brand-new-password-1',
    ], $overrides));
}

function invitationExists(User $user): bool
{
    return DB::table('staff_invitation_tokens')->where('email', $user->email)->exists();
}

// --- happy path -------------------------------------------------------------

test('an inactive nurse or physician can activate their account', function (string $role) {
    $user = invitedStaff(['role' => $role]);
    $token = inviteToken($user);

    $response = activate($user, $token);

    $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $user->refresh();

    expect($user->account_status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->role)->toBe($role);
})->with(['nurse', 'physician']);

test('the submitted password is hashed and usable for login', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    activate($user, $token);
    $user->refresh();

    expect(Hash::check('brand-new-password-1', $user->password))->toBeTrue()
        ->and($user->password === 'brand-new-password-1')->toBeFalse();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'brand-new-password-1',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('the invitation is consumed and cannot be replayed', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    activate($user, $token)->assertSessionHasNoErrors();

    expect(invitationExists($user))->toBeFalse();

    activate($user, $token, [
        'password' => 'second-attempt-password-1',
        'password_confirmation' => 'second-attempt-password-1',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('brand-new-password-1', $user->fresh()->password))->toBeTrue();
});

// --- rejection paths --------------------------------------------------------

test('an invalid token is rejected', function () {
    $user = invitedStaff();
    inviteToken($user);

    activate($user, 'not-a-real-token')->assertSessionHasErrors('email');

    expect($user->fresh()->account_status)->toBe('inactive');
});

test('an expired token is rejected after seven days', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    $this->travelTo(now()->addDays(7)->addMinute());

    activate($user, $token)->assertSessionHasErrors('email');

    expect($user->fresh()->account_status)->toBe('inactive');
});

test('a revoked invitation is rejected', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    Password::broker('staff_invitations')->deleteToken($user);

    activate($user, $token)->assertSessionHasErrors('email');

    expect($user->fresh()->account_status)->toBe('inactive');
});

test('an already active account cannot be activated again', function () {
    $user = invitedStaff(['account_status' => 'active']);
    $token = inviteToken($user);

    activate($user, $token)->assertSessionHasErrors('email');

    expect(Hash::check('brand-new-password-1', $user->fresh()->password))->toBeFalse();
});

test('a suspended account cannot be resurrected by an invitation', function () {
    $user = invitedStaff(['account_status' => 'suspended']);
    $token = inviteToken($user);

    activate($user, $token)->assertSessionHasErrors('email');

    expect($user->fresh()->account_status)->toBe('suspended');
});

test('a deleted user cannot be activated', function () {
    $user = invitedStaff();
    $token = inviteToken($user);
    $email = $user->email;

    $user->delete();

    $this->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => $email,
        'password' => 'brand-new-password-1',
        'password_confirmation' => 'brand-new-password-1',
    ])->assertSessionHasErrors('email');
});

test('a weak password is rejected', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    activate($user, $token, ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->account_status)->toBe('inactive')
        ->and(invitationExists($user))->toBeTrue();
});

test('a mismatched password confirmation is rejected', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    activate($user, $token, ['password_confirmation' => 'a-different-password-1'])
        ->assertSessionHasErrors('password');

    expect($user->fresh()->account_status)->toBe('inactive')
        ->and(invitationExists($user))->toBeTrue();
});

// --- atomicity, roles, rate limiting ---------------------------------------

test('a rejected activation leaves the account completely untouched', function () {
    $user = invitedStaff(['account_status' => 'suspended']);
    $token = inviteToken($user);

    activate($user, $token)->assertSessionHasErrors('email');

    $user->refresh();

    expect($user->account_status)->toBe('suspended')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('brand-new-password-1', $user->password))->toBeFalse()
        ->and(Hash::check('placeholder-never-shared', $user->password))->toBeTrue()
        // the invitation survives a failed attempt rather than being burnt
        ->and(invitationExists($user))->toBeTrue();
});

test('a failure after the account is written rolls the whole activation back', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    // Blow up after the user row is saved but before the broker deletes the
    // token, which is the only window where a partial activation could occur.
    User::updated(function (): void {
        throw new RuntimeException('write failed mid-activation');
    });

    $this->withoutExceptionHandling();

    expect(fn () => activate($user, $token))->toThrow(RuntimeException::class);

    $user->refresh();

    expect($user->account_status)->toBe('inactive')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('brand-new-password-1', $user->password))->toBeFalse()
        ->and(invitationExists($user))->toBeTrue();
});

test('activation cannot change the role of the user', function () {
    $user = invitedStaff(['role' => 'nurse']);
    $token = inviteToken($user);

    activate($user, $token, ['role' => 'admin'])->assertSessionHasNoErrors();

    expect($user->fresh()->role)->toBe('nurse');
});

test('a patient cannot be activated through the staff invitation flow', function () {
    $user = invitedStaff(['role' => 'patient']);
    $token = inviteToken($user);

    activate($user, $token)->assertSessionHasErrors('email');

    expect($user->fresh()->account_status)->toBe('inactive');
});

test('the activation endpoint is rate limited', function () {
    $user = invitedStaff();
    $token = inviteToken($user);

    foreach (range(1, 6) as $ignored) {
        activate($user, 'wrong-token')->assertStatus(302);
    }

    activate($user, $token)->assertStatus(429);

    expect($user->fresh()->account_status)->toBe('inactive');
});

// --- the activation page ----------------------------------------------------

test('the activation page shows the name, email and role of the invitee', function () {
    $user = invitedStaff(['role' => 'physician', 'first_name' => 'Rene', 'last_name' => 'Santos']);
    $token = inviteToken($user);

    $this->get(route('staff.activate', ['token' => $token, 'email' => $user->email]))
        ->assertOk()
        ->assertSee('Rene Santos')
        ->assertSee($user->email)
        ->assertSee('Physician');
});

test('the activation page rejects a bad token instead of revealing the account', function () {
    $user = invitedStaff();
    inviteToken($user);

    $this->get(route('staff.activate', ['token' => 'wrong-token', 'email' => $user->email]))
        ->assertRedirect(route('login'));

    $this->get(route('staff.activate', ['token' => 'wrong-token', 'email' => 'nobody@example.com']))
        ->assertRedirect(route('login'));
});

// --- isolation from the existing password reset flow ------------------------

test('the existing password reset flow still works and is not interchangeable', function () {
    $user = User::factory()->create(['role' => 'patient', 'account_status' => 'active']);

    $resetToken = Password::broker('users')->createToken($user);

    $this->post(route('password.store'), [
        'token' => $resetToken,
        'email' => $user->email,
        'password' => 'reset-password-value-1',
        'password_confirmation' => 'reset-password-value-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('reset-password-value-1', $user->fresh()->password))->toBeTrue();

    // A staff invitation token must not be accepted by the password reset flow.
    $staff = invitedStaff();
    $staffToken = inviteToken($staff);

    $this->post(route('password.store'), [
        'token' => $staffToken,
        'email' => $staff->email,
        'password' => 'should-not-work-value-1',
        'password_confirmation' => 'should-not-work-value-1',
    ])->assertSessionHasErrors('email');

    expect($staff->fresh()->account_status)->toBe('inactive');
});
