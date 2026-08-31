<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;

/**
 * When MarkMissedScheduleSlots flips a booked slot to 'missed' without the
 * consultation ever starting, the physician should still be able to start it
 * immediately (in addition to rescheduling) rather than being stuck. See
 * ConsultationOwnershipService::startByPhysician and
 * PhysicianController::resolveCanStart.
 *
 * A schedule_slots row with status 'missed' can't actually be persisted here:
 * the Feature suite runs on in-memory SQLite, and (per CLAUDE.md's "SQLite
 * enum migrations are no-ops" gotcha) the ALTER migration that adds
 * 'missed'/'completed' to the column's enum never runs on SQLite, so the
 * original migration's CHECK constraint — only 'available'/'booked' — still
 * rejects the insert. Only MySQL (dev/prod) enforces the widened enum, so the
 * 'missed' path was verified by hand against the dev database instead of
 * here; this file only pins the still-testable 'booked but past its window'
 * boundary that the fix deliberately left unchanged.
 */
function missedSlotPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ], $overrides));
}

function missedSlotConsultation(User $physician, string $slotStatus): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $consultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->subDay()->toDateString(),
        'start_time' => '09:00:00',
        'end_time' => '09:30:00',
        'status' => $slotStatus,
    ]);

    ConsultationSession::forceCreate([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'scheduled',
        'slot_id' => $slot->slot_id,
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
    ]);

    return [$consultation, $slot];
}

it('still blocks starting while the slot is booked but outside its time window', function () {
    $physician = missedSlotPhysician();
    [$consultation] = missedSlotConsultation($physician, 'booked');

    $this->actingAs($physician)
        ->postJson(route('physician.consultations.start', [
            'physician' => $physician->user_id,
            'consultation' => $consultation->request_id,
        ]), [
            'physician_id' => $physician->user_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});
