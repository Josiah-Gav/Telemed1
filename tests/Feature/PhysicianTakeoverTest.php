<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Services\ConsultationOwnershipService;

/**
 * Physician Takeover: when the assigned physician has not started a scheduled
 * consultation within ConsultationOwnershipService::TAKEOVER_GRACE_MINUTES of
 * its slot start, another physician may claim it.
 *
 * Time is frozen with travelTo() rather than by shifting slot rows around, so
 * the grace boundary itself is what is under test. Slots are always created
 * 'booked': per CLAUDE.md's "SQLite enum migrations are no-ops" gotcha the
 * Feature suite's in-memory SQLite still carries the original CHECK constraint
 * of 'available'/'booked', so a 'missed' slot cannot be persisted here.
 *
 * The scheduled window used throughout is 2:00 PM - 2:30 PM, which makes the
 * consultation takeover-eligible at exactly 2:10 PM.
 */
const TAKEOVER_SLOT_DATE = '2030-06-10';

function takeoverPhysician(string $lastName): User
{
    return User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
        'first_name' => 'Doctor',
        'last_name' => $lastName,
    ]);
}

/**
 * A scheduled consultation owned by $physician, booked into that physician's
 * own 2:00 PM slot. Returns [$consultation, $session, $slot, $patient].
 */
function takeoverScheduledConsultation(User $physician, array $consultationOverrides = [], array $sessionOverrides = []): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultation = Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ], $consultationOverrides));

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => TAKEOVER_SLOT_DATE,
        'start_time' => '14:00:00',
        'end_time' => '14:30:00',
        'status' => 'booked',
    ]);

    $session = ConsultationSession::forceCreate(array_merge([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => $slot->slot_id,
        'consultation_status' => 'scheduled',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
    ], $sessionOverrides));

    return [$consultation, $session, $slot, $patient];
}

function takeoverClaim(object $test, User $actingAs, User $routePhysician, Consultation $consultation)
{
    return $test->actingAs($actingAs)->postJson(route('physician.consultations.take_over', [
        'physician' => $routePhysician->user_id,
        'consultation' => $consultation->request_id,
    ]));
}

function takeoverStart(object $test, User $physician, Consultation $consultation)
{
    return $test->actingAs($physician)->postJson(route('physician.consultations.start', [
        'physician' => $physician->user_id,
        'consultation' => $consultation->request_id,
    ]), [
        'physician_id' => $physician->user_id,
    ]);
}

/** The moment the 2:00 PM slot becomes claimable: 2:10 PM. */
function takeoverEligibleMoment(): string
{
    $minute = str_pad((string) ConsultationOwnershipService::TAKEOVER_GRACE_MINUTES, 2, '0', STR_PAD_LEFT);

    return TAKEOVER_SLOT_DATE.' 14:'.$minute.':00';
}

// ---------------------------------------------------------------------------
// Untouched behaviour: the assigned physician still starts normally.
// ---------------------------------------------------------------------------

it('lets the assigned physician start their own scheduled consultation normally', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:05:00');

    $physicianA = takeoverPhysician('Alpha');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    takeoverStart($this, $physicianA, $consultation)
        ->assertOk()
        ->assertJsonPath('success', true);

    $session = $session->fresh();

    expect($consultation->fresh()->request_status)->toBe('active')
        ->and($session->consultation_status)->toBe('active')
        ->and((int) $session->physician_id)->toBe($physicianA->user_id)
        ->and($session->taken_over_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// The grace boundary, from both sides.
// ---------------------------------------------------------------------------

it('refuses a takeover one second before the grace period elapses', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:09:59');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

it('allows a takeover at exactly the scheduled time plus the grace period', function () {
    $this->travelTo(takeoverEligibleMoment());

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianB->user_id);
});

// ---------------------------------------------------------------------------
// Assignment moves on both tables; the original assignment stays recorded.
// ---------------------------------------------------------------------------

it('moves the assignment on both tables and records the takeover metadata', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation, $session, $slot] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();

    $consultation = $consultation->fresh();
    $session = $session->fresh();
    $slot = $slot->fresh();

    expect((int) $consultation->assigned_physician_id)->toBe($physicianB->user_id)
        ->and((int) $session->physician_id)->toBe($physicianB->user_id)
        ->and((int) $session->original_physician_id)->toBe($physicianA->user_id)
        ->and((int) $session->taken_over_by_physician_id)->toBe($physicianB->user_id)
        ->and($session->taken_over_at)->not->toBeNull()
        // No new status was invented for takeover.
        ->and($consultation->request_status)->toBe('scheduled')
        ->and($session->consultation_status)->toBe('scheduled')
        // The slot keeps recording who the consultation was scheduled to.
        ->and((int) $slot->physician_id)->toBe($physicianA->user_id)
        ->and($slot->status)->toBe('booked')
        ->and((int) $session->slot_id)->toBe((int) $slot->slot_id);
});

it('notifies the patient that the consultation was reassigned', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation, , , $patient] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();

    $this->assertDatabaseHas('notifications', [
        'user_id' => $patient->user_id,
        'type' => 'consultation_assigned',
        'title' => 'Consultation Reassigned',
    ]);
});

// ---------------------------------------------------------------------------
// Start rights follow the takeover.
// ---------------------------------------------------------------------------

it('stops the original physician starting the consultation after it is taken over', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();

    takeoverStart($this, $physicianA, $consultation)
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect($consultation->fresh()->request_status)->toBe('scheduled')
        ->and($session->fresh()->started_at)->toBeNull();
});

it('lets the claiming physician start through the normal start flow', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();

    takeoverStart($this, $physicianB, $consultation)
        ->assertOk()
        ->assertJsonPath('success', true);

    $session = $session->fresh();

    expect($consultation->fresh()->request_status)->toBe('active')
        ->and($session->consultation_status)->toBe('active')
        ->and((int) $session->physician_id)->toBe($physicianB->user_id)
        ->and($session->started_at)->not->toBeNull()
        // Still auditable after the start.
        ->and((int) $session->original_physician_id)->toBe($physicianA->user_id)
        ->and((int) $session->taken_over_by_physician_id)->toBe($physicianB->user_id);
});

it('lets the claiming physician start even after the original slot window has ended', function () {
    // The claimer is not held to a slot window that belonged to the physician
    // who no-showed; otherwise a late claim would be unusable.
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:25:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();

    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:45:00');

    takeoverStart($this, $physicianB, $consultation)
        ->assertOk()
        ->assertJsonPath('success', true);
});

// ---------------------------------------------------------------------------
// Role authorization.
// ---------------------------------------------------------------------------

it('forbids a nurse from claiming a consultation', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);
    [$consultation] = takeoverScheduledConsultation($physicianA);

    // Under their own id, and impersonating a physician's route id.
    takeoverClaim($this, $nurse, $nurse, $consultation)->assertForbidden();
    takeoverClaim($this, $nurse, $physicianB, $consultation)->assertForbidden();

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

it('forbids a patient from claiming a consultation', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation, , , $patient] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $patient, $patient, $consultation)->assertForbidden();
    takeoverClaim($this, $patient, $physicianB, $consultation)->assertForbidden();

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

it('forbids a physician acting under another physician route id', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    $physicianC = takeoverPhysician('Charlie');
    [$consultation] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianC, $consultation)->assertForbidden();

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

// ---------------------------------------------------------------------------
// Consultation state guards.
// ---------------------------------------------------------------------------

it('cannot claim an already active consultation', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation] = takeoverScheduledConsultation(
        $physicianA,
        ['request_status' => 'active'],
        ['consultation_status' => 'active', 'started_at' => now()],
    );

    takeoverClaim($this, $physicianB, $physicianB, $consultation)
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

it('cannot claim a completed consultation', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation] = takeoverScheduledConsultation(
        $physicianA,
        ['request_status' => 'completed'],
        ['consultation_status' => 'completed', 'started_at' => now(), 'completed_at' => now()],
    );

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertStatus(422);

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

it('cannot claim a cancelled consultation', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation] = takeoverScheduledConsultation($physicianA, ['request_status' => 'cancelled']);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertStatus(422);

    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianA->user_id);
});

it('cannot claim a consultation a second time', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    $physicianC = takeoverPhysician('Charlie');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();

    takeoverClaim($this, $physicianC, $physicianC, $consultation)
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $session = $session->fresh();

    // A second claim must never rewrite the original physician with the first claimant.
    expect((int) $consultation->fresh()->assigned_physician_id)->toBe($physicianB->user_id)
        ->and((int) $session->original_physician_id)->toBe($physicianA->user_id)
        ->and((int) $session->taken_over_by_physician_id)->toBe($physicianB->user_id);
});

it('refuses a physician claiming a consultation already assigned to themselves', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianA, $physicianA, $consultation)->assertStatus(422);

    expect($session->fresh()->taken_over_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Concurrency.
// ---------------------------------------------------------------------------

it('allows exactly one physician to win two concurrent claim attempts', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    $physicianC = takeoverPhysician('Charlie');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    $responses = [
        takeoverClaim($this, $physicianB, $physicianB, $consultation),
        takeoverClaim($this, $physicianC, $physicianC, $consultation),
    ];

    $successes = collect($responses)->filter(fn ($response) => $response->status() === 200);
    $failures = collect($responses)->filter(fn ($response) => $response->status() === 422);

    expect($successes)->toHaveCount(1)
        ->and($failures)->toHaveCount(1);

    // Exactly one winner, and both tables agree on who it is.
    $session = $session->fresh();
    $winnerId = (int) $consultation->fresh()->assigned_physician_id;

    expect($winnerId)->toBe((int) $session->physician_id)
        ->and($winnerId)->toBe((int) $session->taken_over_by_physician_id)
        ->and($winnerId)->toBeIn([$physicianB->user_id, $physicianC->user_id])
        ->and((int) $session->original_physician_id)->toBe($physicianA->user_id);
});

// ---------------------------------------------------------------------------
// Jitsi / video authorization follows consultations.physician_id.
// ---------------------------------------------------------------------------

it('moves video consultation authorization to the claiming physician', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:15:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    [$consultation, $session] = takeoverScheduledConsultation($physicianA);

    takeoverClaim($this, $physicianB, $physicianB, $consultation)->assertOk();
    takeoverStart($this, $physicianB, $consultation)->assertOk();

    $session = $session->fresh();

    expect($physicianB->can('startVideo', $session))->toBeTrue()
        ->and($physicianB->can('joinVideo', $session))->toBeTrue()
        ->and($physicianA->can('startVideo', $session))->toBeFalse()
        ->and($physicianA->can('joinVideo', $session))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Inbox payload: every UI decision is computed server-side.
// ---------------------------------------------------------------------------

it('exposes takeover availability in the physician inbox only after the grace period', function () {
    $this->travelTo(TAKEOVER_SLOT_DATE.' 14:05:00');

    $physicianA = takeoverPhysician('Alpha');
    $physicianB = takeoverPhysician('Bravo');
    takeoverScheduledConsultation($physicianA);

    $inboxUrl = route('physician.consultation_inbox.refresh', ['physician' => $physicianB->user_id]);

    $before = $this->actingAs($physicianB)->getJson($inboxUrl)->assertOk();

    expect($before->json('normalPriorityConsultations.0.takeover_available'))->toBeFalse()
        ->and($before->json('normalPriorityConsultations.0.is_actionable_by_me'))->toBeFalse()
        ->and($before->json('normalPriorityConsultations.0.assigned_physician_name'))->toBe('Doctor Alpha');

    $this->travelTo(takeoverEligibleMoment());

    $after = $this->actingAs($physicianB)->getJson($inboxUrl)->assertOk();

    expect($after->json('normalPriorityConsultations.0.takeover_available'))->toBeTrue()
        ->and($after->json('normalPriorityConsultations.0.waiting_minutes'))
        ->toBe(ConsultationOwnershipService::TAKEOVER_GRACE_MINUTES);

    // The assigned physician never sees a claim action for their own consultation.
    $own = $this->actingAs($physicianA)
        ->getJson(route('physician.consultation_inbox.refresh', ['physician' => $physicianA->user_id]))
        ->assertOk();

    expect($own->json('normalPriorityConsultations.0.takeover_available'))->toBeFalse()
        ->and($own->json('normalPriorityConsultations.0.is_assigned_to_me'))->toBeTrue()
        ->and($own->json('normalPriorityConsultations.0.is_actionable_by_me'))->toBeTrue();
});
