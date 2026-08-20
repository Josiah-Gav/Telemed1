<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\User;

function makePatient(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'patient',
        'user_type' => 'student',
    ], $overrides));
}

function makeNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'nurse',
        'user_type' => 'staff',
    ], $overrides));
}

function makePhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ], $overrides));
}

function makeConsultation(User $patient, array $overrides = []): Consultation
{
    return Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ], $overrides));
}

it('allows only one physician to successfully start the same consultation request', function () {
    $patient = makePatient();
    $physicianA = makePhysician(['first_name' => 'Phys', 'last_name' => 'A']);
    $physicianB = makePhysician(['first_name' => 'Phys', 'last_name' => 'B']);

    $consultation = makeConsultation($patient, [
        'request_status' => 'reviewed',
    ]);

    $this->actingAs($physicianA)
        ->postJson(route('physician.consultations.start', [
            'physician' => $physicianA->user_id,
            'consultation' => $consultation->request_id,
        ]), [
            'physician_id' => $physicianA->user_id,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($physicianB)
        ->postJson(route('physician.consultations.start', [
            'physician' => $physicianB->user_id,
            'consultation' => $consultation->request_id,
        ]), [
            'physician_id' => $physicianB->user_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $consultation->refresh();

    expect((int) $consultation->assigned_physician_id)->toBe((int) $physicianA->user_id);
    expect($consultation->request_status)->toBe('active');

    $sessions = ConsultationSession::query()->where('request_id', $consultation->request_id)->get();
    expect($sessions)->toHaveCount(1);
    expect((int) $sessions->first()->physician_id)->toBe((int) $physicianA->user_id);
});

it('allows only one nurse to claim the same pending consultation request', function () {
    $patient = makePatient();
    $nurseA = makeNurse(['first_name' => 'Nurse', 'last_name' => 'A']);
    $nurseB = makeNurse(['first_name' => 'Nurse', 'last_name' => 'B']);

    $consultation = makeConsultation($patient, [
        'request_status' => 'pending',
    ]);

    $this->actingAs($nurseA)
        ->postJson(route('consultations.approve', ['consultation' => $consultation->request_id]), [
            'priority_level' => 'High',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($nurseB)
        ->postJson(route('consultations.approve', ['consultation' => $consultation->request_id]), [
            'priority_level' => 'Normal',
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $consultation->refresh();

    expect((int) $consultation->assigned_nurse_id)->toBe((int) $nurseA->user_id);
    expect($consultation->request_status)->toBe('reviewed');
    expect($consultation->priority_level)->toBe('High');
});

it('allows only one nurse to forward the same follow-up request', function () {
    $patient = makePatient();
    $nurseA = makeNurse();
    $nurseB = makeNurse();
    $physician = makePhysician();

    $consultation = makeConsultation($patient, [
        'assigned_physician_id' => $physician->user_id,
        'request_status' => 'completed',
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Done',
        'plan' => 'Done',
        'recommendations' => 'Done',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Need follow-up',
        'status' => 'pending',
    ]);

    $this->actingAs($nurseA)
        ->postJson(route('nurse.follow_up_requests.forward', [
            'nurse' => $nurseA->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision_notes' => 'Forwarding to physician.',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($nurseB)
        ->postJson(route('nurse.follow_up_requests.forward', [
            'nurse' => $nurseB->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision_notes' => 'Trying to forward again.',
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $followUpRequest->refresh();

    expect($followUpRequest->status)->toBe('forwarded');
    expect((int) $followUpRequest->reviewed_by_nurse_id)->toBe((int) $nurseA->user_id);
});

it('rejects physician start after patient cancellation commits first', function () {
    $patient = makePatient();
    $physician = makePhysician();

    $consultation = makeConsultation($patient, [
        'request_status' => 'reviewed',
    ]);

    $this->actingAs($patient)
        ->postJson(route('consultations.cancel', ['consultation' => $consultation->request_id]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($physician)
        ->postJson(route('physician.consultations.start', [
            'physician' => $physician->user_id,
            'consultation' => $consultation->request_id,
        ]), [
            'physician_id' => $physician->user_id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $consultation->refresh();
    expect($consultation->request_status)->toBe('cancelled');
});

it('rejects nurse claim after patient cancellation commits first', function () {
    $patient = makePatient();
    $nurse = makeNurse();

    $consultation = makeConsultation($patient, [
        'request_status' => 'pending',
    ]);

    $this->actingAs($patient)
        ->postJson(route('consultations.cancel', ['consultation' => $consultation->request_id]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($nurse)
        ->postJson(route('consultations.approve', ['consultation' => $consultation->request_id]), [
            'priority_level' => 'Normal',
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $consultation->refresh();
    expect($consultation->request_status)->toBe('cancelled');
});

it('rejects patient cancellation after physician start commits first', function () {
    $patient = makePatient();
    $physician = makePhysician();

    $consultation = makeConsultation($patient, [
        'request_status' => 'reviewed',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.consultations.start', [
            'physician' => $physician->user_id,
            'consultation' => $consultation->request_id,
        ]), [
            'physician_id' => $physician->user_id,
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($patient)
        ->postJson(route('consultations.cancel', ['consultation' => $consultation->request_id]))
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $consultation->refresh();
    expect($consultation->request_status)->toBe('active');
    expect((int) $consultation->assigned_physician_id)->toBe((int) $physician->user_id);
});

it('allows only one physician approval path for forwarded follow-up and creates one follow-up session', function () {
    $patient = makePatient();
    $nurse = makeNurse();
    $physicianA = makePhysician(['first_name' => 'Phys', 'last_name' => 'A']);
    $physicianB = makePhysician(['first_name' => 'Phys', 'last_name' => 'B']);

    $consultation = makeConsultation($patient, [
        'assigned_nurse_id' => $nurse->user_id,
        'assigned_physician_id' => $physicianA->user_id,
        'request_status' => 'completed',
    ]);

    $sourceSession = ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physicianA->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Done',
        'plan' => 'Done',
        'recommendations' => 'Done',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = FollowUpRequest::create([
        'consultation_id' => $sourceSession->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Need follow-up',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => $nurse->user_id,
        'reviewed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($physicianA)
        ->postJson(route('physician.follow_up_requests.decide', [
            'physician' => $physicianA->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($physicianB)
        ->postJson(route('physician.follow_up_requests.decide', [
            'physician' => $physicianB->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $followUpRequest->refresh();

    expect($followUpRequest->status)->toBe('approved');
    expect((int) $followUpRequest->decided_by_physician_id)->toBe((int) $physicianA->user_id);

    $followUpConsultations = Consultation::query()
        ->where('type', 'follow_up')
        ->where('parent_consultation_id', $sourceSession->id)
        ->get();

    expect($followUpConsultations)->toHaveCount(1);

    $followUpSessions = ConsultationSession::query()
        ->where('follow_up_request_id', $followUpRequest->id)
        ->get();

    expect($followUpSessions)->toHaveCount(1);
});

it('rejects physician follow-up decision after patient follow-up cancellation commits first', function () {
    $patient = makePatient();
    $nurse = makeNurse();
    $physician = makePhysician();

    $consultation = makeConsultation($patient, [
        'assigned_nurse_id' => $nurse->user_id,
        'assigned_physician_id' => $physician->user_id,
        'request_status' => 'completed',
    ]);

    $sourceSession = ConsultationSession::create([
        'request_id' => $consultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Done',
        'plan' => 'Done',
        'recommendations' => 'Done',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    $followUpRequest = FollowUpRequest::create([
        'consultation_id' => $sourceSession->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Need follow-up',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => $nurse->user_id,
        'reviewed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($patient)
        ->postJson(route('patient.follow_up_requests.cancel', ['followUpRequest' => $followUpRequest->id]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', [
            'physician' => $physician->user_id,
            'followUpRequest' => $followUpRequest->id,
        ]), [
            'decision' => 'approved',
            'mode' => 'immediate',
        ])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    $followUpRequest->refresh();
    expect($followUpRequest->status)->toBe('cancelled');

    $followUpSessions = ConsultationSession::query()
        ->where('follow_up_request_id', $followUpRequest->id)
        ->get();

    expect($followUpSessions)->toHaveCount(0);
});
