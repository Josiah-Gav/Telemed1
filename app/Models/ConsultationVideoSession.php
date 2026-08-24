<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationVideoSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'consultation_id',
        'room_name',
        'ended_at',
    ];

    protected $casts = [
        'ended_at' => 'datetime',
    ];

    /**
     * The clinical session (consultations.id) this video session belongs to.
     */
    public function consultationSession(): BelongsTo
    {
        return $this->belongsTo(ConsultationSession::class, 'consultation_id', 'id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
