<?php

namespace App\Policies;

use App\Models\ConsultationSession;
use App\Models\User;

class ConsultationSessionPolicy
{
    public function viewMessaging(User $user, ConsultationSession $session): bool
    {
        $request = $session->request;

        if (!$request) {
            return false;
        }

        $isPatient = $user->role === 'patient' && (int) $request->patient_id === (int) $user->user_id;
        $isAssignedPhysician = $user->role === 'physician' && (int) $session->physician_id === (int) $user->user_id;

        if (!($isPatient || $isAssignedPhysician)) {
            return false;
        }

        $sessionIsAccessible = in_array($session->consultation_status, ['active', 'completed'], true);
        $requestIsAccessible = in_array($request->request_status, ['active', 'completed'], true);

        return $sessionIsAccessible && $requestIsAccessible;
    }

    public function sendMessage(User $user, ConsultationSession $session): bool
    {
        return $this->viewMessaging($user, $session) && $session->consultation_status === 'active';
    }

    /**
     * Join an already-running video consultation.
     *
     * Delegates to sendMessage rather than viewMessaging on purpose: viewMessaging
     * also permits completed sessions, which must never open or re-enter a room.
     * Nurses are already excluded upstream by viewMessaging.
     */
    public function joinVideo(User $user, ConsultationSession $session): bool
    {
        return $this->sendMessage($user, $session);
    }

    /**
     * Create the video consultation. Physician-initiated only — a patient may join
     * a room but never bring one into existence.
     */
    public function startVideo(User $user, ConsultationSession $session): bool
    {
        return $this->joinVideo($user, $session)
            && $user->role === 'physician'
            && (int) $session->physician_id === (int) $user->user_id;
    }
}
