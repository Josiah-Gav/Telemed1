<?php

use App\Models\Consultation;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Support\DateRange;

/**
 * Phase 5 finding H-1/H-2: the admin dashboard computed "In flight now" as
 * total_pending + total_active in Blade — arithmetic in the view, and
 * silently wrong, since Consultation::IN_FLIGHT_STATUSES is actually
 * pending/reviewed/scheduled/active. A request sitting in 'reviewed'
 * (nurse-claimed, awaiting a physician) or 'scheduled' (physician-booked,
 * not yet started) was invisible to the administrator.
 */
function inFlightPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function inFlightRequest(User $patient, array $overrides = []): Consultation
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

it('counts all four in-flight statuses, not just pending and active', function () {
    $patient = inFlightPatient();
    inFlightRequest($patient, ['request_status' => 'pending']);
    inFlightRequest($patient, ['request_status' => 'reviewed']);
    inFlightRequest($patient, ['request_status' => 'scheduled']);
    inFlightRequest($patient, ['request_status' => 'active']);
    // Concluded — must not be counted.
    inFlightRequest($patient, ['request_status' => 'completed']);
    inFlightRequest($patient, ['request_status' => 'rejected']);
    inFlightRequest($patient, ['request_status' => 'cancelled']);

    $range = DateRange::fromInput('this_year', null, null);
    $result = app(DashboardAnalyticsService::class)->forAdmin($range);

    expect($result['operational']['total_in_flight'])->toBe(4)
        ->and($result['operational']['total_in_flight'])->toBe(Consultation::inFlight()->count());
});

it('exposes an in-flight breakdown that sums to the total', function () {
    $patient = inFlightPatient();
    inFlightRequest($patient, ['request_status' => 'pending']);
    inFlightRequest($patient, ['request_status' => 'pending']);
    inFlightRequest($patient, ['request_status' => 'reviewed']);
    inFlightRequest($patient, ['request_status' => 'scheduled']);
    inFlightRequest($patient, ['request_status' => 'active']);

    $range = DateRange::fromInput('this_year', null, null);
    $breakdown = app(DashboardAnalyticsService::class)->forAdmin($range)['operational']['in_flight_breakdown'];

    expect($breakdown)->toBe([
        'pending' => 2,
        'reviewed' => 1,
        'scheduled' => 1,
        'active' => 1,
    ]);
});

it('does not change total_in_flight when the date range changes, since it is operational not historical', function () {
    $patient = inFlightPatient();
    inFlightRequest($patient, [
        'request_status' => 'reviewed',
        'submitted_at' => now()->subYears(5),
    ]);

    $service = app(DashboardAnalyticsService::class);
    $today = $service->forAdmin(DateRange::fromInput('today', null, null));
    $thisYear = $service->forAdmin(DateRange::fromInput('this_year', null, null));

    expect($today['operational']['total_in_flight'])->toBe(1)
        ->and($thisYear['operational']['total_in_flight'])->toBe(1);
});

it('leaves total_pending and total_active intact alongside the new metric', function () {
    $patient = inFlightPatient();
    inFlightRequest($patient, ['request_status' => 'pending']);
    inFlightRequest($patient, ['request_status' => 'active']);
    inFlightRequest($patient, ['request_status' => 'reviewed']);

    $range = DateRange::fromInput('this_year', null, null);
    $operational = app(DashboardAnalyticsService::class)->forAdmin($range)['operational'];

    expect($operational['total_pending'])->toBe(1)
        ->and($operational['total_active'])->toBe(1)
        ->and($operational['total_in_flight'])->toBe(3);
});

it('never classifies the dead assigned status as in flight', function () {
    $patient = inFlightPatient();
    inFlightRequest($patient, ['request_status' => 'assigned']);

    $range = DateRange::fromInput('this_year', null, null);
    $result = app(DashboardAnalyticsService::class)->forAdmin($range);

    expect($result['operational']['total_in_flight'])->toBe(0);
});

it('renders total_in_flight on the admin dashboard with no arithmetic in Blade', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $patient = inFlightPatient();
    inFlightRequest($patient, ['request_status' => 'reviewed']);
    inFlightRequest($patient, ['request_status' => 'scheduled']);

    $response = $this->actingAs($admin)->get('/dashboard');
    $response->assertOk();
    $html = $response->getContent();

    // The Blade source must not contain a '+' arithmetic expression for
    // this metric — checked against the source file, not the rendered
    // output (rendered output can't prove absence of computation).
    $source = file_get_contents(resource_path('views/admin/dashboard.blade.php'));
    expect($source)->not->toContain("total_pending'] + \$analytics['operational']['total_active");

    expect($html)->toContain('In flight now');
});
