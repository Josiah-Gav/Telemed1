<?php

use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 2 covers the invitation *token* only: the broker wiring and Laravel's
 * DatabaseTokenRepository behaviour. No admin flow, controller, or mail yet.
 *
 * Assertions here never pass a raw or stored token to expect(), so a failure
 * diff cannot print one.
 */

function staffInvitationBroker(): PasswordBroker
{
    return Password::broker('staff_invitations');
}

function staffInvitee(): User
{
    return User::factory()->unverified()->create([
        'role' => 'nurse',
        'account_status' => 'inactive',
    ]);
}

test('the staff invitation broker is configured for exactly seven days', function () {
    $config = config('auth.passwords.staff_invitations');

    expect($config['provider'])->toBe('users')
        ->and($config['table'])->toBe('staff_invitation_tokens')
        ->and($config['expire'])->toBe(10080)
        ->and($config['expire'] * 60)->toBe(7 * 24 * 60 * 60);
});

test('the existing password reset broker is untouched', function () {
    $config = config('auth.passwords.users');

    expect($config['table'])->toBe('password_reset_tokens')
        ->and($config['expire'])->toBe(60)
        ->and($config['throttle'])->toBe(60)
        ->and(config('auth.defaults.passwords'))->toBe('users');
});

test('createToken stores a hashed token, never the plaintext', function () {
    $user = staffInvitee();

    $token = staffInvitationBroker()->createToken($user);

    $record = DB::table('staff_invitation_tokens')->where('email', $user->email)->first();

    expect($record)->not->toBeNull()
        ->and(Hash::check($token, $record->token))->toBeTrue()
        ->and($record->token === $token)->toBeFalse();
});

test('a token is looked up by email and validates only against its own user', function () {
    $user = staffInvitee();
    $other = staffInvitee();

    $token = staffInvitationBroker()->createToken($user);

    expect(staffInvitationBroker()->tokenExists($user, $token))->toBeTrue()
        ->and(staffInvitationBroker()->tokenExists($user, 'not-the-token'))->toBeFalse()
        ->and(staffInvitationBroker()->tokenExists($other, $token))->toBeFalse();
});

test('a token is still valid one minute before seven days', function () {
    $user = staffInvitee();
    $token = staffInvitationBroker()->createToken($user);

    $this->travelTo(now()->addDays(7)->subMinute());

    expect(staffInvitationBroker()->tokenExists($user, $token))->toBeTrue();
});

test('a token has expired one minute after seven days', function () {
    $user = staffInvitee();
    $token = staffInvitationBroker()->createToken($user);

    $this->travelTo(now()->addDays(7)->addMinute());

    expect(staffInvitationBroker()->tokenExists($user, $token))->toBeFalse();
});

test('reset consumes the token so it cannot be replayed', function () {
    $user = staffInvitee();
    $token = staffInvitationBroker()->createToken($user);
    $resolved = null;

    $status = staffInvitationBroker()->reset(
        ['email' => $user->email, 'password' => 'new-password-123', 'token' => $token],
        function (User $found) use (&$resolved) {
            $resolved = $found->user_id;
        }
    );

    expect($status)->toBe(Password::PASSWORD_RESET)
        ->and($resolved)->toBe($user->user_id)
        ->and(DB::table('staff_invitation_tokens')->where('email', $user->email)->exists())->toBeFalse()
        ->and(staffInvitationBroker()->tokenExists($user, $token))->toBeFalse();
});

test('calling createToken again replaces the previous token', function () {
    $user = staffInvitee();

    $first = staffInvitationBroker()->createToken($user);
    $second = staffInvitationBroker()->createToken($user);

    expect(DB::table('staff_invitation_tokens')->where('email', $user->email)->count())->toBe(1)
        ->and(staffInvitationBroker()->tokenExists($user, $first))->toBeFalse()
        ->and(staffInvitationBroker()->tokenExists($user, $second))->toBeTrue();
});

test('deleteToken revokes an outstanding invitation', function () {
    $user = staffInvitee();
    $token = staffInvitationBroker()->createToken($user);

    staffInvitationBroker()->deleteToken($user);

    expect(DB::table('staff_invitation_tokens')->where('email', $user->email)->exists())->toBeFalse()
        ->and(staffInvitationBroker()->tokenExists($user, $token))->toBeFalse();
});

test('the two brokers use separate tables and do not accept each others tokens', function () {
    $user = staffInvitee();

    $invitation = staffInvitationBroker()->createToken($user);
    $reset = Password::broker('users')->createToken($user);

    expect(Password::broker('users')->tokenExists($user, $invitation))->toBeFalse()
        ->and(staffInvitationBroker()->tokenExists($user, $reset))->toBeFalse()
        ->and(DB::table('staff_invitation_tokens')->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);
});

test('the token table carries no user_id, so the custom primary key is irrelevant', function () {
    expect(Schema::hasColumn('staff_invitation_tokens', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumns('staff_invitation_tokens', ['email', 'token', 'created_at']))->toBeTrue();
});
