<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('admins are verified immediately when created', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => null,
    ]);

    expect($admin->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('nurses and physicians are not verified when created', function (string $role) {
    $user = User::factory()->create([
        'role' => $role,
        'email_verified_at' => null,
    ]);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->with(['nurse', 'physician']);

test('clearing a staff email verification is not silently undone on save', function () {
    $nurse = User::factory()->create(['role' => 'nurse']);

    $nurse->email_verified_at = null;
    $nurse->save();

    expect($nurse->fresh()->hasVerifiedEmail())->toBeFalse();
});
