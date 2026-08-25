<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/*
 * Phase 8: scheduled flush of expired staff invitations.
 *
 * The command is Laravel's own auth:clear-resets, named explicitly so it binds
 * to the staff_invitations broker. deleteExpired() removes rows older than the
 * broker's expire window, which is the same boundary tokenExpired() applies, so
 * it can only ever remove a token that has already stopped working.
 */

function cleanupInvitee(string $email): User
{
    return User::factory()->unverified()->create([
        'email' => $email,
        'role' => 'nurse',
        'account_status' => 'inactive',
        'password' => Hash::make('placeholder-never-shared'),
    ]);
}

test('the scheduled command removes expired invitations', function () {
    $stale = cleanupInvitee('stale@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($stale);

    $this->travelTo(now()->addDays(8));

    $this->artisan('auth:clear-resets', ['name' => 'staff_invitations'])->assertSuccessful();

    expect(DB::table('staff_invitation_tokens')->where('email', $stale->email)->exists())->toBeFalse();
});

test('a still-valid invitation survives the cleanup and continues to work', function () {
    $pending = cleanupInvitee('pending@clsu.edu.ph');
    $token = Password::broker('staff_invitations')->createToken($pending);

    // One minute short of the 7-day boundary: expired by the cleanup's own
    // arithmetic would mean expired for activation too, so neither may drop it.
    $this->travelTo(now()->addDays(7)->subMinute());

    $this->artisan('auth:clear-resets', ['name' => 'staff_invitations'])->assertSuccessful();

    expect(DB::table('staff_invitation_tokens')->where('email', $pending->email)->exists())->toBeTrue()
        ->and(Password::broker('staff_invitations')->tokenExists($pending->fresh(), $token))->toBeTrue();

    // And it still activates the account after the sweep.
    $this->post(route('staff.activate.store'), [
        'token' => $token,
        'email' => $pending->email,
        'password' => 'chosen-after-cleanup-1',
        'password_confirmation' => 'chosen-after-cleanup-1',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect($pending->fresh()->account_status)->toBe('active');
});

test('the cleanup separates expired from valid in a single sweep', function () {
    $stale = cleanupInvitee('stale@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($stale);

    $this->travelTo(now()->addDays(6));

    $fresh = cleanupInvitee('fresh@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($fresh);

    $this->travelTo(now()->addDays(2)); // stale is 8 days old, fresh is 2

    $this->artisan('auth:clear-resets', ['name' => 'staff_invitations'])->assertSuccessful();

    expect(DB::table('staff_invitation_tokens')->where('email', $stale->email)->exists())->toBeFalse()
        ->and(DB::table('staff_invitation_tokens')->where('email', $fresh->email)->exists())->toBeTrue();
});

test('the cleanup never touches password reset tokens', function () {
    $staff = cleanupInvitee('staff@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($staff);

    $patient = User::factory()->create(['role' => 'patient', 'account_status' => 'active']);
    Password::broker('users')->createToken($patient);

    // Well past the 60-minute reset expiry: the reset row is stale by its own
    // broker's rules, and must still survive a staff invitation sweep.
    $this->travelTo(now()->addDays(8));

    $this->artisan('auth:clear-resets', ['name' => 'staff_invitations'])->assertSuccessful();

    expect(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(DB::table('staff_invitation_tokens')->count())->toBe(0);
});

test('cleanup does not interfere with a resend issued after the sweep', function () {
    $target = cleanupInvitee('recover@clsu.edu.ph');
    Password::broker('staff_invitations')->createToken($target);

    $this->travelTo(now()->addDays(8));

    $this->artisan('auth:clear-resets', ['name' => 'staff_invitations'])->assertSuccessful();

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->exists())->toBeFalse();

    // The admin can still recover the account, which is the whole point of
    // resend surviving cleanup.
    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('admin.users.resend_invitation', $target))
        ->assertSessionHasNoErrors();

    expect(DB::table('staff_invitation_tokens')->where('email', $target->email)->count())->toBe(1);
});

test('the cleanup command is registered on the scheduler', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->command ?? '');

    expect($events->contains(fn (string $c) => str_contains($c, 'auth:clear-resets staff_invitations')))->toBeTrue();
});
