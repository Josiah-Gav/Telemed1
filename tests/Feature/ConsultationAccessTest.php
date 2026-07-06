<?php

use App\Models\Consultation;
use App\Models\User;

it('allows a patient to view only their own consultation details', function () {
    $owner = User::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'Example',
        'email' => 'alice@example.com',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $otherPatient = User::factory()->create([
        'first_name' => 'Bob',
        'last_name' => 'Example',
        'email' => 'bob@example.com',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $consultation = Consultation::create([
        'patient_id' => $owner->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'fever',
        'symptoms_desc' => [
            ['name' => 'Headache', 'severity' => 'mild'],
        ],
        'file_attachments' => null,
        'request_status' => 'pending',
        'preffered_consultation_type' => 'video',
    ]);

    $this->actingAs($owner)
        ->get(route('consultations.show', $consultation))
        ->assertOk();

    $this->actingAs($otherPatient)
        ->get(route('consultations.show', $consultation))
        ->assertForbidden();
});
