<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;

it('shows a physician initiated follow-up card on the patient dashboard with the scheduled consultation details', function () {
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
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'scheduled',
        'assessment' => 'Follow-up assessment pending.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => null,
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'booked',
    ]);

    $session->update(['slot_id' => $slot->slot_id]);

    $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Physician Follow-up')
        ->assertSee('Scheduled Appointment')
        ->assertSee($slot->slot_date->format('M d, Y'));
});

it('prefers scheduled physician follow-up data over completed follow-up data on the patient dashboard', function () {
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

    $scheduledConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now()->subDay(),
    ]);

    $scheduledSession = ConsultationSession::create([
        'request_id' => $scheduledConsultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'scheduled',
        'assessment' => 'Follow-up assessment pending.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => null,
    ]);

    $slot = ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'booked',
    ]);

    $scheduledSession->update(['slot_id' => $slot->slot_id]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Consultation Status')
        ->assertSee('Scheduled')
        ->assertSee($slot->slot_date->format('M d, Y'));
});

it('hides physician follow-up card when the follow-up consultation is completed', function () {
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

    $completedFollowUp = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Cough', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    ConsultationSession::create([
        'request_id' => $completedFollowUp->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue monitoring.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(5),
    ]);

    $this->actingAs($patient);

    $this->get(route('dashboard'))
        ->assertOk();

    $this->getJson(route('dashboard.active_consultation'))
        ->assertOk()
        ->assertJsonPath('physician_follow_up', null);
});

it('hides the follow-up status card on patient dashboard when latest follow-up request is rejected', function () {
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
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
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
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(10),
    ]);

    \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'Need another follow-up review.',
        'status' => 'rejected',
        'decision_notes' => 'Follow-up not clinically indicated.',
        'decided_by_physician_id' => $physician->user_id,
        'decided_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Your latest follow-up request');
});

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

it('lets a physician directly schedule a follow-up consultation with decision notes and no nurse screening', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
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

    $slot = \App\Models\ScheduleSlot::create([
        'physician_id' => $physician->user_id,
        'slot_date' => now()->addDay()->toDateString(),
        'start_time' => '10:00:00',
        'end_time' => '10:30:00',
        'status' => 'available',
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up.create', ['physician' => $physician->user_id, 'session' => $session->id]), [
            'mode' => 'scheduled',
            'slot_id' => $slot->slot_id,
            'decision_notes' => 'Patient requires a follow-up visit next week.',
        ])
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('consultation_requests', [
        'parent_consultation_id' => $session->id,
        'type' => 'follow_up',
        'request_status' => 'scheduled',
    ]);

    $this->assertDatabaseHas('follow_up_requests', [
        'consultation_id' => $session->id,
        'status' => 'approved',
        'decision_notes' => 'Patient requires a follow-up visit next week.',
        'decided_by_physician_id' => $physician->user_id,
    ]);
});

it('lets a physician approve a forwarded follow-up request without creating a consultation yet', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
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

    $followUpRequest = \App\Models\FollowUpRequest::create([
        'consultation_id' => $session->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need a review.',
        'status' => 'forwarded',
        'reviewed_by_nurse_id' => null,
        'reviewed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->postJson(route('physician.follow_up_requests.decide', ['physician' => $physician->user_id, 'followUpRequest' => $followUpRequest->id]), [
            'decision' => 'approved',
        ])
        ->assertJsonPath('success', true);

    $followUpRequest->refresh();

    $this->assertSame('approved', $followUpRequest->status);
    $this->assertDatabaseCount('consultations', 1);
    $this->assertDatabaseMissing('consultations', [
        'type' => 'follow_up',
    ]);
});

it('shows rejected follow-up request decision notes and rejected consultation reason in patient consultation history', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $physician = User::factory()->create([
        'first_name' => 'Liam',
        'last_name' => 'Parker',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $rejectedConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'rejected',
        'rejection_reason' => 'Initial screening suggests in-person assessment is required.',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $sourceConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'follow-up care',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $sourceSession = ConsultationSession::create([
        'request_id' => $sourceConsultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Follow-up assessment complete.',
        'plan' => 'Continue observation.',
        'recommendations' => 'Return if symptoms worsen.',
        'assigned_at' => now()->subHours(2),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(15),
    ]);

    \App\Models\FollowUpRequest::create([
        'consultation_id' => $sourceSession->id,
        'patient_id' => $patient->user_id,
        'reason' => 'I still need follow-up support.',
        'status' => 'rejected',
        'decision_notes' => 'Follow-up request rejected due to insufficient clinical indication.',
        'decided_by_physician_id' => $physician->user_id,
        'decided_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.history'))
        ->assertOk()
        ->assertSee('Decision for Rejection')
        ->assertSee($rejectedConsultation->rejection_reason)
        ->assertSee('Decision Notes')
        ->assertSee('Follow-up request rejected due to insufficient clinical indication.');
});

it('filters patient consultation history by status and consultation type', function () {
    $patient = User::factory()->create([
        'first_name' => 'Mina',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now()->subDay(),
    ]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'cancelled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ]);

    $this->actingAs($patient)
        ->get(route('consultations.history', ['status' => 'cancelled', 'consultation_type' => 'general', 'date_filter' => 'all']))
        ->assertOk()
        ->assertSee('Cancelled')
        ->assertDontSee('Follow-up Fatigue Consultation');
});

it('filters physician consultation history and searches by patient or nurse name', function () {
    $physician = User::factory()->create([
        'first_name' => 'Gregory',
        'last_name' => 'House',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $nurse = User::factory()->create([
        'first_name' => 'Nina',
        'last_name' => 'Lopez',
        'role' => 'nurse',
        'user_type' => 'staff',
    ]);

    $patientOne = User::factory()->create([
        'first_name' => 'Mario',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $patientTwo = User::factory()->create([
        'first_name' => 'Lara',
        'last_name' => 'Cruz',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientOne->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'type' => 'follow_up',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now()->subDay(),
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientTwo->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'cancelled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
        'submitted_at' => now(),
    ]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history', [
            'physician' => $physician->user_id,
            'status' => 'completed',
            'consultation_type' => 'follow_up',
            'date_filter' => 'all',
            'search' => 'Mario',
        ]))
        ->assertOk()
        ->assertSee('Mario Santos')
        ->assertSee('Nina Lopez')
        ->assertDontSee('Lara Cruz');
});

it('returns filtered physician consultation history HTML payload for ajax live search', function () {
    $physician = User::factory()->create([
        'first_name' => 'Gregory',
        'last_name' => 'House',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $patientOne = User::factory()->create([
        'first_name' => 'Mario',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $patientTwo = User::factory()->create([
        'first_name' => 'Lara',
        'last_name' => 'Cruz',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientOne->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need follow-up review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    Consultation::forceCreate([
        'patient_id' => $patientTwo->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history', [
            'physician' => $physician->user_id,
            'search' => 'Mario',
            'status' => 'all',
            'consultation_type' => 'all',
            'date_filter' => 'all',
        ]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJsonStructure(['html'])
        ->assertJsonPath('html', function (string $html) {
            return str_contains($html, 'Mario Santos') && !str_contains($html, 'Lara Cruz');
        });
});

it('hides schedule follow-up action for general consultations that already have a follow-up', function () {
    $physician = User::factory()->create([
        'first_name' => 'Gregory',
        'last_name' => 'House',
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $patient = User::factory()->create([
        'first_name' => 'Mario',
        'last_name' => 'Santos',
        'role' => 'patient',
        'user_type' => 'student',
    ]);

    $generalConsultation = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Need initial review',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $sourceSession = ConsultationSession::create([
        'request_id' => $generalConsultation->request_id,
        'physician_id' => $physician->user_id,
        'slot_id' => null,
        'consultation_status' => 'completed',
        'assessment' => 'Initial consultation complete.',
        'plan' => 'Observe for one week.',
        'recommendations' => 'Hydrate and rest.',
        'assigned_at' => now()->subDays(1),
        'started_at' => now()->subDays(1)->addMinutes(15),
        'completed_at' => now()->subHours(2),
    ]);

    Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'follow_up',
        'parent_consultation_id' => $sourceSession->id,
        'concern_category' => 'fatigue',
        'symptoms_desc' => [['name' => 'Fatigue', 'severity' => 'mild']],
        'online_reason' => 'Follow-up arranged',
        'request_status' => 'scheduled',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $this->actingAs($physician)
        ->get(route('physician.consultation_history', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertDontSee('data-consultation-id="' . $generalConsultation->request_id . '"');
});