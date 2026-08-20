<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FollowUpRequest extends Model
{
    use HasFactory;

    protected $table = 'follow_up_requests';

    protected $fillable = [
        'consultation_id',
        'patient_id',
        'reviewed_by_nurse_id',
        'decided_by_physician_id',
        'reason',
        'status',
        'decision_notes',
        'reviewed_at',
        'decided_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(ConsultationSession::class, 'consultation_id', 'id');
    }

    public function followUpConsultation(): HasOne
    {
        return $this->hasOne(ConsultationSession::class, 'follow_up_request_id', 'id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id', 'user_id');
    }

    public function reviewedByNurse(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_nurse_id', 'user_id');
    }

    public function decidedByPhysician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_physician_id', 'user_id');
    }
}