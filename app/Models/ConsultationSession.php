<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ConsultationSession extends Model
{
    use HasFactory;

    protected $table = 'consultations';

    protected $fillable = [
        'request_id',
        'physician_id',
        'consultation_status',
        'assessment',
        'plan',
        'recommendations',
        'prescription_file_name',
        'prescription_file_path',
        'prescription_mime_type',
        'prescription_file_size',
        'diagnosis',
        'cancellation_reason',
        'follow_up_required',
        'follow_up_date',
        'assigned_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'follow_up_required' => 'boolean',
        'follow_up_date' => 'date',
        'prescription_file_size' => 'integer',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Consultation::class, 'request_id', 'request_id');
    }

    public function physician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'physician_id', 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'consultation_id', 'id')->orderBy('created_at', 'asc');
    }

    public function hasMeaningfulAssessment(): bool
    {
        return $this->hasMeaningfulText($this->assessment, [
            'initial assessment pending.',
        ]);
    }

    public function hasMeaningfulPlan(): bool
    {
        return $this->hasMeaningfulText($this->plan, [
            'plan to be documented during consultation.',
        ]);
    }

    public function hasMeaningfulRecommendations(): bool
    {
        return $this->hasMeaningfulText($this->recommendations, [
            'recommendations to follow after evaluation.',
        ]);
    }

    public function hasDiagnosis(): bool
    {
        return filled(trim((string) $this->diagnosis));
    }

    public function hasPrescription(): bool
    {
        return filled($this->prescription_file_path);
    }

    public function hasClinicalDocumentation(): bool
    {
        return $this->hasMeaningfulAssessment()
            || $this->hasMeaningfulPlan()
            || $this->hasMeaningfulRecommendations()
            || $this->hasDiagnosis();
    }

    private function hasMeaningfulText(?string $value, array $placeholders = []): bool
    {
        $normalizedValue = Str::lower(trim((string) $value));

        if ($normalizedValue === '') {
            return false;
        }

        $normalizedPlaceholders = array_map(
            static fn (string $placeholder) => Str::lower(trim($placeholder)),
            $placeholders
        );

        return !in_array($normalizedValue, $normalizedPlaceholders, true);
    }
}
