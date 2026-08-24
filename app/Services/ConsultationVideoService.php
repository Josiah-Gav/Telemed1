<?php

namespace App\Services;

use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns the video session lifecycle for a consultation.
 *
 * Every write locks the parent `consultations` row first. That row always exists,
 * so it serialises correctly on every driver — unlike locking the video row, which
 * may not exist yet and therefore cannot reliably block a concurrent insert. This
 * is the same pattern ConsultationOwnershipService uses for claim/start/schedule.
 */
class ConsultationVideoService
{
    public function __construct(private readonly JitsiService $jitsi) {}

    /**
     * Create the consultation's video session, or reuse the one already running.
     *
     * Only the assigned physician may do this; a patient never creates a room.
     */
    public function startForPhysician(ConsultationSession $session, User $physician): ConsultationVideoSession
    {
        return DB::transaction(function () use ($session, $physician) {
            $lockedSession = ConsultationSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-checked under the lock: the policy ran against a possibly stale model.
            if ((int) $lockedSession->physician_id !== (int) $physician->user_id) {
                throw new RuntimeException('Only the assigned physician can start this video consultation.');
            }

            $this->assertConsultationIsActive($lockedSession);

            $existing = $lockedSession->activeVideoSession()->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            return ConsultationVideoSession::create([
                'consultation_id' => $lockedSession->id,
                'room_name' => $this->jitsi->generateRoomName(),
            ]);
        });
    }

    /**
     * The running video session, or null when the physician has not started one.
     */
    public function activeFor(ConsultationSession $session): ?ConsultationVideoSession
    {
        return $session->activeVideoSession()->first();
    }

    /**
     * Close the running video session. Returns false when there was nothing to close.
     */
    public function end(ConsultationSession $session): bool
    {
        return (bool) DB::transaction(function () use ($session) {
            $lockedSession = ConsultationSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            $active = $lockedSession->activeVideoSession()->lockForUpdate()->first();

            if (! $active) {
                return false;
            }

            $active->update(['ended_at' => now()]);

            return true;
        });
    }

    /**
     * The ConsultationSession lifecycle is the source of truth for whether video is
     * allowed — there is deliberately no separate video status machine.
     */
    private function assertConsultationIsActive(ConsultationSession $session): void
    {
        if ($session->consultation_status !== 'active') {
            throw new RuntimeException('Only active consultations can use video consultation.');
        }

        if (optional($session->request)->request_status !== 'active') {
            throw new RuntimeException('Only active consultations can use video consultation.');
        }
    }
}
