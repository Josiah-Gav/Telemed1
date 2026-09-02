<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * Renders each dashboard with real, seeded data and inspects the actual
 * HTML — not just a 200 status — for the structural/accessibility contracts
 * the design system requires: no "assigned" status leaking through, chart
 * canvases carrying an accessible name, the filter boundary present, and
 * the shared-queue/personal-workload surfaces both rendering.
 */
function renderPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function renderNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function renderPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function renderRequest(User $patient, array $overrides = []): Consultation
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

it('renders the admin dashboard with accessible chart canvases and no assigned status leakage', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $patient = renderPatient();
    renderRequest($patient, ['request_status' => 'completed']);
    renderRequest($patient, ['request_status' => 'rejected']);
    renderRequest($patient, [
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 4]],
    ]);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $html = $response->getContent();

    // Chart canvases carry an accessible name and the payload contract.
    expect($html)->toContain('role="img"')
        ->toContain('data-chart-payload')
        ->toContain('data-chart-type="severity"')
        ->toContain('data-chart-type="hbar-status"');

    // The dead 'assigned' status is never rendered as a status word.
    expect(strtolower($html))->not->toContain('>assigned<');

    // Completion Rate is labelled correctly, never "success rate".
    expect($html)->toContain('Completion Rate')
        ->not->toContain('Success Rate');

    // The filter boundary and its scope note are present.
    expect($html)->toContain('id="range"')
        ->toContain('not affected by the date filter');

    // Symptom section explains the follow-up exclusion in visible text.
    expect($html)->toContain('Follow-up consultations repeat the original');

    // The privacy note for suppressed custom terms is present.
    expect($html)->toContain('hidden when reported fewer than 3 times');
});

it('shows a separate chart of custom symptom terms, not merged into the standardized chart', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $patient = renderPatient();

    // "Migraine" is not in SymptomAnalytics::STANDARDIZED_SYMPTOMS, so this
    // reaches the k=3 qualifying threshold and must appear in its own chart
    // rather than folded into "Headache" for sounding similar.
    renderRequest($patient, ['symptoms_desc' => [['name' => 'Migraine', 'severity' => 2]]]);
    renderRequest($patient, ['symptoms_desc' => [['name' => 'Migraine', 'severity' => 2]]]);
    renderRequest($patient, ['symptoms_desc' => [['name' => 'Migraine', 'severity' => 2]]]);

    // Reported only twice — stays below the privacy floor and must not leak.
    renderRequest($patient, ['symptoms_desc' => [['name' => 'Brain Fog', 'severity' => 1]]]);
    renderRequest($patient, ['symptoms_desc' => [['name' => 'Brain Fog', 'severity' => 1]]]);

    $html = $this->actingAs($admin)->get('/dashboard')->getContent();

    expect($html)->toContain('id="admin-custom-symptoms-chart"')
        ->toContain('Most reported custom symptoms')
        ->toContain('Migraine')
        ->not->toContain('Brain Fog');
});

it('renders the nurse dashboard with the shared queue visually and structurally distinct from personal workload', function () {
    $nurse = renderNurse();
    $patient = renderPatient();
    renderRequest($patient, ['request_status' => 'pending', 'assigned_nurse_id' => null]);
    renderRequest($patient, ['request_status' => 'reviewed', 'assigned_nurse_id' => $nurse->user_id]);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('id="shared-queue-heading"')
        ->toContain('id="my-workload-heading"')
        ->toContain('Shared across all nurses')
        ->toContain('Assigned to you')
        ->toContain('My Completed — Selected Period');
});

it('renders the physician dashboard scoped to the authenticated physician only', function () {
    $physicianA = renderPhysician();
    $physicianB = renderPhysician();
    $patient = renderPatient();
    renderRequest($patient, ['request_status' => 'active', 'assigned_physician_id' => $physicianA->user_id]);
    renderRequest($patient, ['request_status' => 'active', 'assigned_physician_id' => $physicianB->user_id]);

    $response = $this->actingAs($physicianA)->get(route('physician.dashboard', ['physician' => $physicianA->user_id]));

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('>1<') // active_now = 1, scoped to physician A only
        ->toContain('Completion Rate')
        ->toContain('Completed consultations as a percentage of concluded requests');
});

it('never crashes when a request has malformed symptoms_desc', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $patient = renderPatient();
    renderRequest($patient, ['symptoms_desc' => []]);

    $this->actingAs($admin)->get('/dashboard')->assertOk();
});
