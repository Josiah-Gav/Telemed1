<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

it('lets a patient request a follow-up for a completed consultation within the allowed window', function () {
    $patient = User::factory()->create([
        'first_name' => 'Maya',
        'last_name' => 'Reyes',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Noah',
        'last_name' => 'Flores',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [
            ['name' => 'Cough', 'severity' => 'mild'],
        ],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'diagnosis' => 'Recovered',
        'cancellation_reason' => null,
        'follow_up_required' => false,
        'follow_up_date' => null,
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($patient)
        ->post(route('patient.follow_up_requests.store', ['session' => $session]), [
            'reason' => 'I still have symptoms and need a review.',
        ])
        ->assertRedirect(route('patient.follow_up_list'));

    $this->assertDatabaseHas('follow_up_requests', [
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'status' => 'pending',
        'reason' => 'I still have symptoms and need a review.',
    ]);
});