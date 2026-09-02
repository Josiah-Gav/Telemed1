<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

test('physician mobile bottom nav links to physician routes, not the patient-only ones', function () {
    $physician = User::factory()->create(['role' => 'physician']);

    $response = $this->actingAs($physician)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('physician.consultation_inbox', ['physician' => $physician]), false);
    $response->assertSee(route('physician.consultation_history', ['physician' => $physician]), false);
    $response->assertDontSee(route('newconsultation'), false);
    $response->assertDontSee(route('consultations.history'), false);
});

test('physician mobile bottom nav has full parity with the desktop sidebar', function () {
    $physician = User::factory()->create(['role' => 'physician']);

    $response = $this->actingAs($physician)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('physician.follow_up_requests', ['physician' => $physician]), false);
    $response->assertSee(route('physician.active_consultation', ['physician' => $physician]), false);
    $response->assertSee(route('physician.scheduled_consultation', ['physician' => $physician]), false);
});

test('patient mobile bottom nav is unchanged', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $response = $this->actingAs($patient)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('newconsultation'), false);
    $response->assertSee(route('consultations.history'), false);
});

/*
| The patient floating action button is a shortcut into the one consultation a
| patient can have running at a time. It must not appear when there is nothing
| running, and when it does appear it must point at that consultation's
| messaging page — the same session ConsultationSessionPolicy would let them open.
*/

test('patient has no floating action button without a running consultation', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $response = $this->actingAs($patient)->get('/dashboard');

    $response->assertOk();
    $response->assertDontSee('aria-label="Active Consultation"', false);
});

test('patient floating action button links to the running consultation messaging page', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician']);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $response = $this->actingAs($patient)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('consultations.messaging.show', $session), false);
});

test('patient floating action button disappears once the consultation completes', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $physician = User::factory()->create(['role' => 'physician']);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => 'initial',
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 2]],
        'online_reason' => 'Need consultation',
        'request_status' => 'completed',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);

    ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'completed',
        'assessment' => '',
        'plan' => '',
        'recommendations' => '',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    $response = $this->actingAs($patient)->get('/dashboard');

    $response->assertOk();
    $response->assertDontSee('aria-label="Active Consultation"', false);
});

test('nurse mobile bottom nav has full parity with the desktop sidebar', function () {
    $nurse = User::factory()->create(['role' => 'nurse']);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse]));

    $response->assertOk();
    $response->assertSee(route('nurse.consultation_inbox', ['nurse' => $nurse]), false);
    $response->assertSee(route('nurse.follow_up_requests', ['nurse' => $nurse]), false);
    $response->assertSee(route('nurse.consultation_history', ['nurse' => $nurse]), false);
});

test('admin mobile bottom nav links to user management, not the patient-only routes', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('admin.users.index'), false);
    $response->assertDontSee(route('newconsultation'), false);
    $response->assertDontSee(route('consultations.history'), false);
});
