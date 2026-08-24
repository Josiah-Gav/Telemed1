<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;

/*
| This repo has no browser-level JS test runner (no Jest/Vitest/Playwright — see
| package.json), and the messaging page's video affordance is entirely client-side
| reactive (Alpine's x-show toggles visibility at runtime, not via server-side @if).
| So, matching this project's existing precedent for testing Blade+Alpine behavior
| (tests/Feature/MobileBottomNavigationTest.php asserts on rendered markup/route
| strings rather than driving a browser), these tests assert on the server-rendered
| source: that the Join affordance is correctly gated by videoActive, that it posts
| to the real authorized /video/join route, that Jitsi is only ever constructed from
| that endpoint's response, and that no credential is embedded in the page.
|
| The endpoints these markup/wiring assertions rely on (/video/join, /video/start,
| presence) already have full behavioral coverage in ConsultationVideoAccessTest.php
| and ConsultationVideoPresenceTest.php — this file does not re-test that behavior.
*/

/**
 * The layout also includes a notifications dropdown Alpine component
 * (layouts.notificationUI) that defines its own init(), earlier in the page than
 * consultationMessaging(). Scope every source slice to start after
 * "function consultationMessaging()" so position-based assertions can't accidentally
 * match that unrelated component's methods.
 */
function messagingComponentHtml(string $html): string
{
    $start = strpos($html, 'function consultationMessaging()');

    expect($start)->not->toBeFalse();

    return substr($html, $start);
}

function makeVideoUiScenario(): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ]);

    $consultationRequest = Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'assigned_nurse_id' => null,
        'type' => 'initial',
        'parent_consultation_id' => null,
        'concern_category' => 'Headache',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 'mild']],
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'file_attachments' => null,
    ]);

    $session = ConsultationSession::create([
        'request_id' => $consultationRequest->request_id,
        'physician_id' => $physician->user_id,
        'consultation_status' => 'active',
        'assessment' => 'Initial assessment pending.',
        'plan' => 'Plan to be documented during consultation.',
        'recommendations' => 'Recommendations to follow after evaluation.',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    return compact('patient', 'physician', 'session');
}

it('initializes videoActive to false and never seeds it from the server', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $response = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk();

    // Must be the literal default, never computed server-side from the video row —
    // the presence poll is the only thing allowed to set it after page load.
    $response->assertSee('videoActive: false,', false);
});

it('gates the join affordance on videoActive and never automatically joins', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $response = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk();

    // The banner only renders for an active consultation, and only for the physician
    // (who may start) or once a room is actually live. The Join button inside it is
    // additionally gated on videoActive, so a patient never sees Join before the start.
    $response->assertSee(
        "x-show=\"!inVideoCall && consultationStatus === 'active' && (isAssignedPhysician || videoActive)\"",
        false
    );
    $response->assertSee('x-show="videoActive"', false);
    $response->assertSee('@click="joinVideoCall"', false);
    $response->assertSee('Join Video Consultation', false);

    // No call to joinVideoCall() or the join endpoint from init() or fetchPresence():
    // joining only ever happens from the explicit button click.
    $html = messagingComponentHtml($response->getContent());
    $initBlock = substr($html, strpos($html, 'init() {'), strpos($html, 'markOffline() {') - strpos($html, 'init() {'));
    expect($initBlock)->not->toContain('joinVideoCall')
        ->not->toContain('startVideoCall');

    // fetchPresence() ends where startVideoCall() begins (the next method defined in the
    // component); this bound must not sweep in the start/join method bodies themselves.
    $presenceBlock = substr($html, strpos($html, 'fetchPresence() {'), strpos($html, 'startVideoCall() {') - strpos($html, 'fetchPresence() {'));
    expect($presenceBlock)->not->toContain('joinVideoCall(')
        ->not->toContain('startVideoCall(');
});

it('wires the join button to the real authorized /video/join route, not a bypass', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $response = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk();

    $response->assertSee(
        "videoJoinUrl: '".route('consultations.video.join', $session)."'",
        false
    );

    $html = $response->getContent();
    $joinFn = substr($html, strpos($html, 'joinVideoCall() {'), strpos($html, 'loadJitsiExternalApi(domain, tenant) {') - strpos($html, 'joinVideoCall() {'));

    expect($joinFn)->toContain('url: this.videoJoinUrl')
        ->toContain("method: 'POST'")
        ->toContain('X-CSRF-TOKEN');
});

it('constructs the jitsi client only inside an authorized start/join success path, never on page load or from presence', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // The IFrame API constructor call exists exactly once, inside startJitsiCall(), which
    // is only ever invoked from the success handlers of POST /video/start or /video/join.
    expect(substr_count($html, 'new window.JitsiMeetExternalAPI'))->toBe(1);

    $startFnPos = strpos($html, 'startJitsiCall(joinData) {');
    $constructorPos = strpos($html, 'new window.JitsiMeetExternalAPI');
    expect($startFnPos)->not->toBeFalse()
        ->and($constructorPos)->toBeGreaterThan($startFnPos);

    // external_api.js is not a static <script src> tag: it must load lazily, after an
    // authorized join, using the domain the server returned — never eagerly on page load.
    expect($html)->not->toMatch('/<script[^>]+src="https:\/\/[^"]*\/external_api\.js"/');

    // JaaS serves the library under the tenant path. The bare-domain URL
    // (https://8x8.vc/external_api.js) 404s, so the tenant segment is required.
    expect($html)->toContain('script.src = `https://${domain}/${tenant}/external_api.js`')
        ->not->toContain('script.src = `https://${domain}/external_api.js`');

    // The tenant is derived from the authorized response, never hard-coded in the page.
    expect($html)->toContain("const tenant = String(joinData.room_name || '').split('/')[0]");
});

it('reads only the active boolean from presence, never a room or credential field', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    $presenceBlock = substr(
        $html,
        strpos($html, 'fetchPresence() {'),
        strpos($html, 'startVideoCall() {') - strpos($html, 'fetchPresence() {')
    );

    expect($presenceBlock)->toContain('data.video || {}).active')
        ->not->toContain('data.video.jwt')
        ->not->toContain('data.video.room_name')
        ->not->toContain('data.video.domain');
});

it('never embeds a jitsi credential or hard-coded app identifier in the rendered page', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    foreach (['8x8.vc', 'vpaas-magic-cookie', config('services.jitsi.api_key_id'), 'BEGIN PRIVATE KEY'] as $sensitive) {
        if ($sensitive === '' || $sensitive === null) {
            continue;
        }

        expect(str_contains($html, (string) $sensitive))->toBeFalse();
    }
});

it('resets videoActive to false at the point the consultation is marked completed', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    $completeSuccessBlock = substr(
        $html,
        strpos($html, 'url: this.completeUrl'),
        strpos($html, 'error: (xhr) => {', strpos($html, 'url: this.completeUrl')) - strpos($html, 'url: this.completeUrl')
    );

    expect($completeSuccessBlock)->toContain('this.videoActive = false');
});

it('tears down an in-progress call if presence reports the video session is no longer active', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $html = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    $presenceBlock = substr(
        $html,
        strpos($html, 'fetchPresence() {'),
        strpos($html, 'startVideoCall() {') - strpos($html, 'fetchPresence() {')
    );

    expect($presenceBlock)->toContain('this.leaveVideoCall()');
});

it('offers the start affordance to the assigned physician only', function () {
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = makeVideoUiScenario();

    $physicianHtml = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    $patientHtml = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk()
        ->getContent();

    // The button markup ships to both, but the role flag is what gates it at runtime.
    expect($physicianHtml)->toContain('isAssignedPhysician: true')
        ->and($patientHtml)->toContain('isAssignedPhysician: false');

    // Start is additionally gated on no room already running; End is physician-only.
    expect($physicianHtml)->toContain('x-show="isAssignedPhysician && !videoActive"')
        ->toContain('Start Video Consultation')
        ->toContain('End call for everyone');
});

it('wires start to the authorized /video/start route and joins the room immediately', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoUiScenario();

    $response = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk();

    $response->assertSee(
        "videoStartUrl: '".route('consultations.video.start', $session)."'",
        false
    );

    $html = messagingComponentHtml($response->getContent());
    $startFn = substr($html, strpos($html, 'startVideoCall() {'), strpos($html, 'endVideoCall() {') - strpos($html, 'startVideoCall() {'));

    expect($startFn)->toContain('url: this.videoStartUrl')
        ->toContain("method: 'POST'")
        ->toContain('X-CSRF-TOKEN')
        // Start → immediate join: the same payload boots the iframe with no second click.
        ->toContain('this.startJitsiCall(data)')
        ->toContain('this.videoActive = true');
});

it('wires end to the authorized /video/end route and tears the call down locally', function () {
    ['physician' => $physician, 'session' => $session] = makeVideoUiScenario();

    $response = $this->actingAs($physician)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk();

    $response->assertSee(
        "videoEndUrl: '".route('consultations.video.end', $session)."'",
        false
    );

    $html = messagingComponentHtml($response->getContent());
    $endFn = substr($html, strpos($html, 'endVideoCall() {'), strpos($html, 'joinVideoCall() {') - strpos($html, 'endVideoCall() {'));

    expect($endFn)->toContain('url: this.videoEndUrl')
        ->toContain("method: 'POST'")
        ->toContain('X-CSRF-TOKEN')
        ->toContain('this.videoActive = false')
        ->toContain('this.leaveVideoCall()')
        // Ending is a server action, not a local dispose masquerading as one.
        ->not->toContain('new window.JitsiMeetExternalAPI');
});

it('leaves the existing messaging and presence markup unchanged', function () {
    ['patient' => $patient, 'session' => $session] = makeVideoUiScenario();

    $response = $this->actingAs($patient)
        ->get(route('consultations.messaging.show', $session))
        ->assertOk();

    $response->assertSee('No messages yet. Start the consultation conversation.', false);
    $response->assertSee('peerOnline ? \'Online\' : \'Offline\'', false);
    $response->assertSee('x-show="peerIsTyping"', false);
    $response->assertSee("presenceUrl: '".route('consultations.messaging.presence', $session)."'", false);
});
