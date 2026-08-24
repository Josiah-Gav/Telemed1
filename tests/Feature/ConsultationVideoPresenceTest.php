<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\User;

function makePresenceScenario(array $sessionOverrides = [], array $requestOverrides = []): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    $consultationRequest = Consultation::forceCreate(array_merge([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => $nurse->user_id,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ], $requestOverrides));

    $session = ConsultationSession::create(array_merge([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ], $sessionOverrides));

    return compact('patient', 'physician', 'nurse', 'session', 'consultationRequest');
}

function startPresenceVideoRoom(ConsultationSession $session, string $roomName): ConsultationVideoSession
{
    return ConsultationVideoSession::create([
        'consultation_id' => $session->id,
        'room_name' => $roomName,
    ]);
}

it('reports video inactive when no video session has ever existed', function () {
    ['patient' => $patient, 'session' => $session] = makePresenceScenario();

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk()
        ->assertJsonPath('video.active', false);
});

it('reports video active once the physician has started a video session', function () {
    ['patient' => $patient, 'session' => $session] = makePresenceScenario();
    startPresenceVideoRoom($session, str_repeat('a', 32));

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk()
        ->assertJsonPath('video.active', true);
});

it('reports video inactive again once the video session has ended', function () {
    ['patient' => $patient, 'session' => $session] = makePresenceScenario();
    $videoSession = startPresenceVideoRoom($session, str_repeat('b', 32));
    $videoSession->update(['ended_at' => now()]);

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk()
        ->assertJsonPath('video.active', false);
});

it('reports video inactive for a completed consultation even with a stale open row', function () {
    ['patient' => $patient, 'session' => $session] = makePresenceScenario(
        ['consultation_status' => 'completed', 'completed_at' => now()],
        ['request_status' => 'completed'],
    );

    // Simulate a stale row that was never closed, bypassing the normal completion
    // path entirely, to prove presence does not trust row existence alone.
    startPresenceVideoRoom($session, str_repeat('c', 32));

    $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk()
        ->assertJsonPath('video.active', false);
});

it('never exposes any jitsi credential or identifier through presence', function () {
    ['patient' => $patient, 'session' => $session] = makePresenceScenario();
    startPresenceVideoRoom($session, str_repeat('d', 32));

    $response = $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk();

    // Pin the exact key shapes: nothing beyond a boolean can hide inside "video".
    $response->assertJsonStructure([
        'peer' => ['user_id', 'name', 'is_typing', 'is_online', 'last_seen_at'],
        'video' => ['active'],
    ]);

    $data = $response->json();
    expect(array_keys($data))->toBe(['peer', 'video'])
        ->and(array_keys($data['video']))->toBe(['active']);

    $rawBody = $response->getContent();
    foreach (['jwt', 'room_name', 'domain', 'app_id', 'api_key_id', 'private_key', '8x8.vc', 'vpaas-magic-cookie'] as $sensitive) {
        expect(str_contains(strtolower($rawBody), strtolower($sensitive)))->toBeFalse();
    }
});

it('shows the same video state to both the patient and the physician', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makePresenceScenario();
    startPresenceVideoRoom($session, str_repeat('e', 32));

    $patientView = $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk()
        ->json('video.active');

    $physicianView = $this->actingAs($physician)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk()
        ->json('video.active');

    expect($patientView)->toBe(true)->and($physicianView)->toBe(true);
});

it('still refuses presence to a nurse', function () {
    ['nurse' => $nurse, 'session' => $session] = makePresenceScenario();

    $this->actingAs($nurse)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertForbidden();
});

it('isolates video presence state per consultation session', function () {
    ['patient' => $patientA, 'session' => $sessionA] = makePresenceScenario();
    ['patient' => $patientB, 'session' => $sessionB] = makePresenceScenario();

    startPresenceVideoRoom($sessionA, str_repeat('f', 32));
    // sessionB has no video session at all.

    $this->actingAs($patientA)
        ->getJson(route('consultations.messaging.presence', $sessionA))
        ->assertOk()
        ->assertJsonPath('video.active', true);

    $this->actingAs($patientB)
        ->getJson(route('consultations.messaging.presence', $sessionB))
        ->assertOk()
        ->assertJsonPath('video.active', false);
});

it('leaves the existing peer payload shape and values unchanged', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makePresenceScenario();

    $response = $this->actingAs($patient)
        ->getJson(route('consultations.messaging.presence', $session))
        ->assertOk();

    $response->assertJsonStructure([
        'peer' => ['user_id', 'name', 'is_typing', 'is_online', 'last_seen_at'],
    ]);

    $peer = $response->json('peer');

    expect($peer['user_id'])->toBe($physician->user_id)
        ->and($peer['name'])->toBe(trim($physician->first_name.' '.$physician->last_name))
        ->and($peer['is_typing'])->toBeFalse();
});
