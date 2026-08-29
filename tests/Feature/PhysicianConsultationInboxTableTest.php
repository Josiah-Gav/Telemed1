<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;

/**
 * Coverage for the physician consultation inbox's auto-refresh poll: the
 * table used to be rendered once from Blade collections and never updated
 * when a patient's request was newly assigned/reviewed after page load.
 * physicianConsultationInbox() now keeps its Alpine `consultations` array as
 * the single source of truth for both tables (via normalPriorityConsultations/
 * highPriorityConsultations getters) and refreshes it by polling the
 * physician.consultation_inbox.refresh endpoint.
 */
function inboxPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

function physicianInboxConsultation(array $overrides = []): Consultation
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'reviewed',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ], $overrides));
}

it('embeds assigned consultations and the refresh endpoint URL the auto-refresh poll uses', function () {
    $physician = inboxPhysician();
    physicianInboxConsultation();

    $response = $this->actingAs($physician)->get(route('physician.consultation_inbox', ['physician' => $physician->user_id]));

    $response->assertOk();
    // @json() escapes forward slashes, so compare against the same encoding.
    $response->assertSee(json_encode(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id])), false);
});

it('reports normal and high priority consultations via the refresh endpoint', function () {
    $physician = inboxPhysician();
    physicianInboxConsultation(['priority_level' => 'Normal']);
    physicianInboxConsultation(['priority_level' => 'High']);

    $response = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]));

    $response->assertOk();
    expect($response->json('normalPriorityConsultations'))->toHaveCount(1);
    expect($response->json('highPriorityConsultations'))->toHaveCount(1);
});

/**
 * The table renders rows with Alpine x-for, so it cannot use the
 * <x-dash.badge> Blade component per row. PhysicianController serializes the
 * same App\Support\StatusBadge tokens instead — these assertions pin that
 * contract, since a missing token renders an empty cell rather than an error.
 */
it('serializes StatusBadge tokens for status, priority and severity', function () {
    $physician = inboxPhysician();
    physicianInboxConsultation([
        'priority_level' => 'High',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2], ['name' => 'Fever', 'severity' => 4]],
    ]);

    $row = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]))
        ->assertOk()
        ->json('highPriorityConsultations.0');

    // Highest severity across the payload wins, not the first one listed.
    expect($row['severity'])->toBe(4);
    expect($row['severity_badge']['label'])->toBe('4 - Severe');
    expect($row['status_badge']['label'])->toBe('Reviewed');
    expect($row['priority_badge']['label'])->toBe('High Priority');
});

it('renders a non-empty badge for the assigned status the physician inbox actually queries', function () {
    $physician = inboxPhysician();
    physicianInboxConsultation(['request_status' => 'assigned']);

    $row = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]))
        ->assertOk()
        ->json('normalPriorityConsultations.0');

    expect($row['status_badge'])->not->toBeNull();
    expect($row['status_badge']['label'])->toBe('Assigned');
});

it('reports N/A severity rather than an empty badge when no symptom is scored', function () {
    $physician = inboxPhysician();
    physicianInboxConsultation(['symptoms_desc' => [['name' => 'Headache']]]);

    $row = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]))
        ->assertOk()
        ->json('normalPriorityConsultations.0');

    expect($row['severity'])->toBeNull();
    expect($row['severity_badge']['label'])->toBe('N/A');
});

/**
 * A stored attachment may be a local disk path that is not web-reachable, so
 * the modal's thumbnail grid must be handed routed URLs, never the raw value.
 */
it('exposes attachments as routed URLs instead of raw stored paths', function () {
    $physician = inboxPhysician();
    $consultation = physicianInboxConsultation([
        'file_attachments' => ['consultation_files/scan.png'],
    ]);

    $row = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]))
        ->assertOk()
        ->json('normalPriorityConsultations.0');

    expect($row['file_attachments'][0])->toBe(route('consultation.attachment', [
        'consultation' => $consultation->request_id,
        'file' => 'scan.png',
    ]));
});

it('carries the can_start gate the modal uses to disable the Start button', function () {
    $physician = inboxPhysician();
    physicianInboxConsultation(['request_status' => 'reviewed']);

    $row = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]))
        ->assertOk()
        ->json('normalPriorityConsultations.0');

    expect($row)->toHaveKeys(['can_start', 'can_start_message', 'scheduled_slot', 'additional_information']);
    expect($row['can_start'])->toBeTrue();
});

/**
 * The Scheduled Slot column shows slot_date directly, so it carries the same
 * human format as Submitted At. starts_at_iso is asserted alongside it because
 * it is derived from the same date and would silently break if the display
 * format were applied before the ISO string is built.
 */
it('formats the scheduled slot date for display without breaking starts_at_iso', function () {
    $physician = inboxPhysician();
    $consultation = physicianInboxConsultation(['request_status' => 'scheduled']);

    $slot = ScheduleSlot::forceCreate([
        'physician_id' => $physician->user_id,
        'slot_date' => '2026-08-29',
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    ConsultationSession::forceCreate([
        'request_id' => $consultation->request_id,
        'consultation_status' => 'scheduled',
        'slot_id' => $slot->slot_id,
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
    ]);

    $row = $this->actingAs($physician)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physician->user_id]))
        ->assertOk()
        ->json('normalPriorityConsultations.0');

    expect($row['scheduled_slot']['slot_date'])->toBe('Aug. 29, 2026');
    expect($row['scheduled_slot']['label'])->toBe('2:00 PM - 2:30 PM');
    expect($row['scheduled_slot']['starts_at_iso'])->toStartWith('2026-08-29T14:00:00');
});
