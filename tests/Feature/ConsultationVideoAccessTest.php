<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\User;

/*
| Tokens here are signed with a throwaway keypair generated at runtime, never with the
| real configured credentials, and no assertion is handed a credential value.
*/

function videoAccessTestPrivateKey(): string
{
    static $privateKey = null;

    if ($privateKey !== null) {
        return $privateKey;
    }

    $options = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    foreach ([getenv('OPENSSL_CONF'), 'C:\xampp\php\extras\ssl\openssl.cnf', 'C:\xampp\apache\conf\openssl.cnf'] as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            $options['config'] = $candidate;
            break;
        }
    }

    $resource = openssl_pkey_new($options);

    if ($resource === false) {
        test()->markTestSkipped('OpenSSL cannot generate an RSA keypair in this environment.');
    }

    openssl_pkey_export($resource, $exported, null, $options);

    return $privateKey = $exported;
}

function makeVideoAccessScenario(array $sessionOverrides = [], array $requestOverrides = []): array
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

beforeEach(function () {
    config()->set('services.jitsi', [
        'domain' => '8x8.vc',
        'app_id' => 'vpaas-magic-cookie-testtenant0000000000000000000',
        'api_key_id' => 'vpaas-magic-cookie-testtenant0000000000000000000/abc123',
        'private_key' => videoAccessTestPrivateKey(),
        'jwt_ttl' => 1800,
    ]);
});

it('lets the assigned physician start the video consultation', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $response = $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('is_moderator', true)
        ->assertJsonPath('domain', '8x8.vc');

    $videoSession = ConsultationVideoSession::where('consultation_id', $session->id)->sole();

    expect($videoSession->room_name)->toMatch('/^[0-9a-f]{32}$/')
        ->and($videoSession->ended_at)->toBeNull()
        // The client gets the "{app_id}/{room}" form, never the bare name.
        ->and($response->json('room_name'))
        ->toBe(config('services.jitsi.app_id').'/'.$videoSession->room_name)
        ->and($response->json('jwt'))->toBeString()->not->toBeEmpty();
});

it('refuses to let an unassigned physician start the video consultation', function () {
    ['session' => $session] = makeVideoAccessScenario();

    $otherPhysician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'Cardiology',
    ]);

    $this->actingAs($otherPhysician)
        ->postJson(route('consultations.video.start', $session))
        ->assertForbidden();

    expect(ConsultationVideoSession::count())->toBe(0);
});

it('refuses to let a patient start a video consultation', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoAccessScenario();

    $this->actingAs($patient)
        ->postJson(route('consultations.video.start', $session))
        ->assertForbidden();

    expect(ConsultationVideoSession::count())->toBe(0);
});

it('lets the patient join a video consultation the physician already started', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    $this->actingAs($patient)
        ->postJson(route('consultations.video.join', $session))
        ->assertOk()
        ->assertJsonPath('success', true)
        // The patient is never a moderator.
        ->assertJsonPath('is_moderator', false);

    expect(ConsultationVideoSession::where('consultation_id', $session->id)->count())->toBe(1);
});

it('refuses to let the patient join before the physician has started, and creates nothing', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoAccessScenario();

    $this->actingAs($patient)
        ->postJson(route('consultations.video.join', $session))
        ->assertStatus(409)
        ->assertJsonPath('success', false);

    expect(ConsultationVideoSession::count())->toBe(0);
});

it('refuses to let a patient join another patient\'s consultation', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $otherPatient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    $this->actingAs($otherPatient)
        ->postJson(route('consultations.video.join', $session))
        ->assertForbidden();
});

it('refuses to let a nurse start or join the video consultation', function () {
    ['nurse' => $nurse, 'physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $this->actingAs($nurse)
        ->postJson(route('consultations.video.start', $session))
        ->assertForbidden();

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    // Even once a room exists, the assigned nurse still cannot enter it.
    $this->actingAs($nurse)
        ->postJson(route('consultations.video.join', $session))
        ->assertForbidden();
});

it('refuses video on a completed consultation', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makeVideoAccessScenario(
        ['consultation_status' => 'completed', 'completed_at' => now()],
        ['request_status' => 'completed'],
    );

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertForbidden();

    $this->actingAs($patient)
        ->postJson(route('consultations.video.join', $session))
        ->assertForbidden();

    expect(ConsultationVideoSession::count())->toBe(0);
});

it('refuses video on a scheduled consultation that has not started', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario(
        ['consultation_status' => 'scheduled', 'started_at' => null],
        ['request_status' => 'scheduled'],
    );

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertForbidden();

    expect(ConsultationVideoSession::count())->toBe(0);
});

it('reuses the existing active video session when the physician starts again', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $first = $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    $second = $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    expect($second->json('room_name'))->toBe($first->json('room_name'))
        ->and(ConsultationVideoSession::where('consultation_id', $session->id)->count())->toBe(1);
});

it('cannot create a duplicate active video session from repeated concurrent starts', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    // Sequential stand-in for concurrency, matching ConsultationConcurrencyTest: each
    // request runs the same lock-then-check transaction, so the second must observe
    // the first one's committed row rather than inserting its own.
    //
    // ponytail: proves the reuse branch, not lock behaviour under true parallelism.
    // In-memory SQLite runs on one connection, so genuinely simultaneous requests
    // cannot be expressed here. Upgrade path if it ever matters: a MySQL integration
    // test driving two connections against a shared database. Deferred deliberately —
    // not worth standing up that test infrastructure for this feature.
    $rooms = collect(range(1, 5))->map(function () use ($physician, $session) {
        return $this->actingAs($physician)
            ->postJson(route('consultations.video.start', $session))
            ->assertOk()
            ->json('room_name');
    });

    expect($rooms->unique()->count())->toBe(1)
        ->and(ConsultationVideoSession::where('consultation_id', $session->id)->whereNull('ended_at')->count())
        ->toBe(1);
});

it('gives a follow-up consultation a different room from its parent', function () {
    ['physician' => $physician, 'session' => $parentSession] = makeVideoAccessScenario();

    ['session' => $followUpSession] = makeVideoAccessScenario([], [
        'type' => 'follow_up',
        'parent_consultation_id' => $parentSession->id,
    ]);

    $parentRoom = $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $parentSession))
        ->assertOk()
        ->json('room_name');

    $followUpRoom = $this->actingAs($followUpSession->physician)
        ->postJson(route('consultations.video.start', $followUpSession))
        ->assertOk()
        ->json('room_name');

    expect($followUpRoom)->not->toBe($parentRoom);
});

it('lets the assigned physician end the video consultation', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    $this->actingAs($physician)
        ->postJson(route('consultations.video.end', $session))
        ->assertOk()
        ->assertJsonPath('ended', true);

    expect(ConsultationVideoSession::where('consultation_id', $session->id)->whereNull('ended_at')->count())->toBe(0);

    // The patient can no longer join a closed room.
    $this->actingAs($session->request->patient)
        ->postJson(route('consultations.video.join', $session))
        ->assertStatus(409);
});

it('refuses to let a patient end the video consultation', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))
        ->assertOk();

    $this->actingAs($patient)
        ->postJson(route('consultations.video.end', $session))
        ->assertForbidden();

    expect(ConsultationVideoSession::where('consultation_id', $session->id)->whereNull('ended_at')->count())->toBe(1);
});

it('starts a brand new room after the previous one was ended', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoAccessScenario();

    $firstRoom = $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))->assertOk()->json('room_name');

    $this->actingAs($physician)->postJson(route('consultations.video.end', $session))->assertOk();

    $secondRoom = $this->actingAs($physician)
        ->postJson(route('consultations.video.start', $session))->assertOk()->json('room_name');

    expect($secondRoom)->not->toBe($firstRoom)
        ->and(ConsultationVideoSession::where('consultation_id', $session->id)->count())->toBe(2)
        ->and(ConsultationVideoSession::where('consultation_id', $session->id)->whereNull('ended_at')->count())->toBe(1);
});

it('requires authentication for every video endpoint', function () {
    ['session' => $session] = makeVideoAccessScenario();

    $this->postJson(route('consultations.video.start', $session))->assertUnauthorized();
    $this->postJson(route('consultations.video.join', $session))->assertUnauthorized();
    $this->postJson(route('consultations.video.end', $session))->assertUnauthorized();
});
