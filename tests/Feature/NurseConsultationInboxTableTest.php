<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * Coverage for the nurse consultation inbox tables (pending / assigned to me
 * / assigned to other nurses) after the Phase 4 redesign: the Symptoms
 * column was removed, Submitted At switched to the app's human-readable
 * date format, and Status/Priority render via <x-dash.badge> instead of
 * hand-rolled badge markup.
 */
function inboxNurse(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'nurse', 'user_type' => 'staff'], $overrides));
}

function inboxPatient(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'patient', 'user_type' => 'student'], $overrides));
}

function inboxConsultation(array $overrides = []): Consultation
{
    return Consultation::forceCreate(array_merge([
        'patient_id' => inboxPatient()->user_id,
        'assigned_physician_id' => null,
        'assigned_nurse_id' => null,
        'concern_category' => 'General',
        'symptoms_desc' => [['name' => 'Headache', 'severity' => 3]],
        'online_reason' => 'Need consultation',
        'request_status' => 'pending',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ], $overrides));
}

/**
 * The modal still has its own "Symptoms" section (unrelated, kept from the
 * Phase 3 redesign), so a page-wide assertDontSee('Symptoms') would be a
 * false positive. This checks specifically for the removed <th> column
 * header, which used this exact class/text combination in all three tables.
 */
function containsSymptomsColumnHeader(string $html): bool
{
    return str_contains($html, '<th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Symptoms</th>');
}

it('does not show a Symptoms column on the pending table but keeps Severity, Status, and Submitted At', function () {
    $nurse = inboxNurse();
    inboxConsultation(['request_status' => 'pending']);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    expect(containsSymptomsColumnHeader($response->getContent()))->toBeFalse();
    $response->assertSee('Severity');
    $response->assertSee('Submitted At');
    $response->assertSee(now()->format('M. j, Y'), false);
});

it('does not show a Symptoms column on the assigned-to-me table but keeps Priority', function () {
    $nurse = inboxNurse();
    inboxConsultation(['assigned_nurse_id' => $nurse->user_id, 'request_status' => 'reviewed']);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    expect(containsSymptomsColumnHeader($response->getContent()))->toBeFalse();
    $response->assertSee('Priority');
});

it('renders the assigned-to-other-nurses table with the assigned nurse name', function () {
    $viewingNurse = inboxNurse();
    $otherNurse = inboxNurse(['first_name' => 'Grace', 'last_name' => 'Tan']);
    inboxConsultation(['assigned_nurse_id' => $otherNurse->user_id, 'request_status' => 'reviewed']);

    $response = $this->actingAs($viewingNurse)->get(route('nurse.consultation_inbox', ['nurse' => $viewingNurse->user_id]));

    $response->assertOk();
    $response->assertSee('Grace Tan');
});

it('shows the empty state when no pending requests exist', function () {
    $nurse = inboxNurse();

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('No pending consultation requests found.');
});

/**
 * The Phase 5 auto-refresh poll (consultationInbox()'s init()/checkForNewPending()
 * in this file) calls this endpoint directly, so it is no longer dead code —
 * it needs its own coverage now.
 */
it('reports the current pending count via the refresh endpoint the auto-refresh poll uses', function () {
    $nurse = inboxNurse();
    inboxConsultation(['request_status' => 'pending']);
    inboxConsultation(['request_status' => 'pending']);

    $response = $this->actingAs($nurse)
        ->getJson(route('nurse.consultation_inbox.refresh', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    expect($response->json('pendingRequests'))->toHaveCount(2);
});

it('refuses another nurse\'s route parameter on the refresh endpoint', function () {
    $nurseA = inboxNurse();
    $nurseB = inboxNurse();

    $this->actingAs($nurseA)
        ->getJson(route('nurse.consultation_inbox.refresh', ['nurse' => $nurseB->user_id]))
        ->assertForbidden();
});

it('marks an online patient with a visible dot and an accessible label on the pending table', function () {
    $nurse = inboxNurse();
    $onlinePatient = inboxPatient([
        'online_status' => 'online',
        'last_seen_at' => now(),
    ]);
    inboxConsultation(['patient_id' => $onlinePatient->user_id, 'request_status' => 'pending']);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('bg-emerald-500', false);
    $response->assertSee('Online');
});

it('marks a patient last seen over 2 minutes ago as offline, not online', function () {
    // Note: the modal's Alpine :class="... 'bg-emerald-500' ..." expression is
    // always present in the page's raw HTML (it's JS source text, not a
    // server-computed value), so this asserts the offline branch rendered
    // rather than asserting 'bg-emerald-500' is absent from the page.
    $nurse = inboxNurse();
    $stalePatient = inboxPatient([
        'online_status' => 'online',
        'last_seen_at' => now()->subMinutes(5),
    ]);
    inboxConsultation(['patient_id' => $stalePatient->user_id, 'request_status' => 'pending']);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('bg-gray-300', false);
});

/**
 * Phase 6: attachments switched from target="_blank" links to an in-page
 * lightbox (openAttachmentPreview()/closeAttachmentPreview() in this file).
 */
it('renders an attachment as a thumbnail button rather than a target="_blank" link', function () {
    $nurse = inboxNurse();
    inboxConsultation([
        'request_status' => 'pending',
        'file_attachments' => ['https://res.cloudinary.com/demo/image/upload/photo.jpg'],
    ]);

    $response = $this->actingAs($nurse)->get(route('nurse.consultation_inbox', ['nurse' => $nurse->user_id]));

    $response->assertOk();
    $response->assertSee('openAttachmentPreview(file)', false);
    $response->assertDontSee('target="_blank" class="font-medium text-brand-green hover:underline"', false);
});
