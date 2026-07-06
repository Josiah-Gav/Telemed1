<?php

namespace App\Policies;

use App\Models\Consultation;
use App\Models\User;

class ConsultationPolicy
{
    /**
     * Determine whether the user can view the consultation details.
     */
    public function view(User $user, Consultation $consultation): bool
    {
        return $user->role === 'patient' && $consultation->patient_id === $user->user_id;
    }
}
