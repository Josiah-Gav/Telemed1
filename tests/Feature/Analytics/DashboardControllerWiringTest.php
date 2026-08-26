<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * Proves the thin-controller wiring: authorize, build a DateRange from the
 * query string, call DashboardAnalyticsService, pass the result to the
 * existing view. Does not touch or assert on Blade output — the views are
 * untouched in this phase and simply receive extra, currently-unused data.
 */
function wiringPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function wiringNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function wiringPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function wiringRequest(User $patient, array $overrides = []): Consultation
{
    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'type' => 'initial',
        'concern_category' => 'general',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ], $overrides));
}

it('passes admin analytics data to the admin dashboard view', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    wiringRequest(wiringPatient(), ['request_status' => 'completed']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertViewHas('analytics', function (array $analytics) {
        return $analytics['period']['completed'] === 1
            && array_key_exists('operational', $analytics)
            && array_key_exists('charts', $analytics)
            && array_key_exists('symptoms', $analytics);
    });
});

it('respects the range query string on the admin dashboard', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard?range=this_month');

    $response->assertOk();
    $response->assertViewHas('dateRange', fn ($range) => $range->preset === 'this_month');
});

it('falls back gracefully instead of erroring on an invalid range value', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard?range=not-a-real-preset');

    $response->assertOk();
});

it('passes nurse analytics scoped to the authenticated nurse', function () {
    $nurse = wiringNurse();
    $patient = wiringPatient();
    wiringRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => null]);
    wiringRequest($patient, [
        'request_status' => 'reviewed',
        'assigned_nurse_id' => $nurse->user_id,
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertViewHas('analytics', function (array $analytics) {
        return $analytics['operational']['unclaimed_pending'] === 1
            && $analytics['operational']['my_open_cases']['total'] === 1;
    });
});

it('passes physician analytics scoped to the authenticated physician', function () {
    $physician = wiringPhysician();
    $patient = wiringPatient();
    wiringRequest($patient, [
        'request_status' => 'active',
        'assigned_physician_id' => $physician->user_id,
    ]);

    $response = $this->actingAs($physician)->get(route('physician.dashboard', ['physician' => $physician->user_id]));

    $response->assertOk();
    $response->assertViewHas('analytics', fn (array $analytics) => $analytics['operational']['active_now'] === 1);
});

it('still refuses a nurse viewing another nurse\'s dashboard', function () {
    $nurseA = wiringNurse();
    $nurseB = wiringNurse();

    $this->actingAs($nurseA)
        ->get(route('nurse.dashboard', ['nurse' => $nurseB->user_id]))
        ->assertForbidden();
});
