<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function makeVideoConsultationSession(array $sessionOverrides = [], array $requestOverrides = []): ConsultationSession
{
    $patient = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ], $requestOverrides));

    return ConsultationSession::create(array_merge([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ], $sessionOverrides));
}

it('keeps historical video sessions for one consultation session, newest first', function () {
    $session = makeVideoConsultationSession();

    // forceCreate so the back-dated created_at values actually stick (created_at is not fillable).
    ConsultationVideoSession::forceCreate([
        'consultation_id' => $session->id,
        'room_name' => 'room-oldest',
        'ended_at' => now()->subHours(2),
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(2),
    ]);

    ConsultationVideoSession::forceCreate([
        'consultation_id' => $session->id,
        'room_name' => 'room-middle',
        'ended_at' => now()->subHour(),
        'created_at' => now()->subHours(2),
        'updated_at' => now()->subHour(),
    ]);

    ConsultationVideoSession::forceCreate([
        'consultation_id' => $session->id,
        'room_name' => 'room-current',
        'ended_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($session->videoSessions()->pluck('room_name')->all())
        ->toBe(['room-current', 'room-middle', 'room-oldest']);
});

it('treats the only video session without an ended_at as the active one', function () {
    $session = makeVideoConsultationSession();

    ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => 'room-finished',
        'ended_at' => now()->subHour(),
    ]);

    ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => 'room-live',
        'ended_at' => null,
    ]);

    $active = $session->activeVideoSession()->first();

    expect($active)->not->toBeNull()
        ->and($active->room_name)->toBe('room-live')
        ->and($active->isActive())->toBeTrue();
});

it('reports no active video session once every video session has ended', function () {
    $session = makeVideoConsultationSession();

    $videoSession = ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => 'room-to-end',
    ]);

    expect($session->activeVideoSession()->first())->not->toBeNull();

    $videoSession->update(['ended_at' => now()]);

    expect($session->activeVideoSession()->first())->toBeNull()
        ->and($videoSession->fresh()->isActive())->toBeFalse()
        ->and($session->videoSessions()->count())->toBe(1);
});

it('locks the parent consultation session before reading the active video session', function () {
    $session = makeVideoConsultationSession();

    ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => 'room-locked',
    ]);

    // This is the exact read the service layer will perform inside its transaction:
    // lock the always-present parent row, then resolve the (possibly absent) child.
    $found = DB::transaction(function () use ($session) {
        $lockedSession = ConsultationSession::query()
            ->whereKey($session->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $lockedSession->activeVideoSession()
            ->lockForUpdate()
            ->first();
    });

    expect($found)->not->toBeNull()
        ->and($found->room_name)->toBe('room-locked');
});

it('rejects a jitsi room name that is already in use', function () {
    $sessionOne = makeVideoConsultationSession();
    $sessionTwo = makeVideoConsultationSession();

    ConsultationVideoSession::create([
        'consultation_id' => $sessionOne->id,
        'room_name' => 'duplicate-room',
    ]);

    expect(fn () => ConsultationVideoSession::create([
        'consultation_id' => $sessionTwo->id,
        'room_name' => 'duplicate-room',
    ]))->toThrow(QueryException::class);
});

it('removes video sessions when the consultation session is deleted', function () {
    $session = makeVideoConsultationSession();

    ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => 'room-cascaded',
    ]);

    $session->delete();

    expect(ConsultationVideoSession::where('room_name', 'room-cascaded')->exists())->toBeFalse();
});

it('gives a follow-up consultation session its own video session and room', function () {
    $parentSession = makeVideoConsultationSession();

    $followUpSession = makeVideoConsultationSession([], [
        'type' => 'follow_up',
        'parent_consultation_id' => $parentSession->id,
    ]);

    $parentRoom = ConsultationVideoSession::create([
        'consultation_id' => $parentSession->id,
        'room_name' => 'room-parent',
    ]);

    $followUpRoom = ConsultationVideoSession::create([
        'consultation_id' => $followUpSession->id,
        'room_name' => 'room-follow-up',
    ]);

    expect($followUpSession->id)->not->toBe($parentSession->id)
        ->and($followUpRoom->room_name)->not->toBe($parentRoom->room_name)
        ->and($parentSession->activeVideoSession()->first()->id)->toBe($parentRoom->id)
        ->and($followUpSession->activeVideoSession()->first()->id)->toBe($followUpRoom->id);
});
