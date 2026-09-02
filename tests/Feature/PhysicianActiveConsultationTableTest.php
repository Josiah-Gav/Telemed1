<?php

use App\Models\Consultation;
use App\Models\User;

/**
 * The active-consultation table was redesigned to match the consultation
 * inbox's theme: the "Consultation Type" column was removed, and the
 * comma-joined symptoms cell became a capped chip list (at most 3 symptoms)
 * with a dynamic "+N more" indicator. See PhysicianController::activeConsultations()
 * and resources/views/physician/active_consultation.blade.php.
 */
function activeConsultationPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'physician',
        'user_type' => 'staff',
        'specialization' => 'General Medicine',
    ], $overrides));
}

function activeConsultationFor(User $physician, array $symptoms, string $type = 'initial'): Consultation
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);

    return Consultation::forceCreate([
        'patient_id' => $patient->user_id,
        'assigned_physician_id' => $physician->user_id,
        'type' => $type,
        'concern_category' => 'Headache',
        'symptoms_desc' => $symptoms,
        'online_reason' => 'Need consultation',
        'request_status' => 'active',
        'priority_level' => 'Normal',
        'submitted_at' => now(),
    ]);
}

it('drops the consultation type column from the active consultations table', function () {
    $physician = activeConsultationPhysician();
    activeConsultationFor($physician, [['name' => 'Headache', 'severity' => 2]]);

    $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertDontSee('Consultation Type');
});

it('caps the symptoms cell at 3 chips and shows a dynamic +N more indicator', function () {
    $physician = activeConsultationPhysician();
    activeConsultationFor($physician, [
        ['name' => 'Headache', 'severity' => 2],
        ['name' => 'Fever', 'severity' => 3],
        ['name' => 'Cough', 'severity' => 1],
        ['name' => 'Fatigue', 'severity' => 2],
        ['name' => 'Nausea', 'severity' => 1],
    ]);

    $response = $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk();

    $response->assertSee('+2 more');

    // Every symptom name is also embedded in the page's JSON payload (the
    // modal needs the full list regardless of the table's cap) and the
    // overflow badge's title attribute, so this checks for the exact chip
    // markup rather than just the bare name anywhere in the response.
    $html = $response->getContent();
    $chipMarkup = fn (string $name) => '<span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-700">'.$name.'</span>';

    expect($html)->toContain($chipMarkup('Headache'));
    expect($html)->toContain($chipMarkup('Fever'));
    expect($html)->toContain($chipMarkup('Cough'));
    expect($html)->not->toContain($chipMarkup('Fatigue'));
    expect($html)->not->toContain($chipMarkup('Nausea'));
});

it('shows no overflow indicator when there are 3 or fewer symptoms', function () {
    $physician = activeConsultationPhysician();
    activeConsultationFor($physician, [
        ['name' => 'Headache', 'severity' => 2],
        ['name' => 'Fever', 'severity' => 3],
    ]);

    $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk()
        ->assertDontSee('more');
});

it('drops the assigned nurse column and formats the submitted date like the consultation inbox', function () {
    $physician = activeConsultationPhysician();
    $consultation = activeConsultationFor($physician, [['name' => 'Headache', 'severity' => 2]]);
    // submitted_at is Consultation::CREATED_AT, not a $fillable attribute, so
    // a mass-assigned update() silently drops it — forceFill bypasses that.
    $consultation->forceFill([
        'assigned_nurse_id' => User::factory()->create(['role' => 'nurse', 'user_type' => 'staff'])->user_id,
        'submitted_at' => \Carbon\CarbonImmutable::parse('2026-08-29 14:00:00'),
    ])->save();

    $response = $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk()
        // Same PhysicianController::serializeConsultations() format used by
        // the consultation inbox ('M. j, Y g:i A'), not the old 'Y-m-d H:i'.
        ->assertSee('Aug. 29, 2026 2:00 PM')
        ->assertDontSee('2026-08-29 14:00');

    // "Assigned Nurse" still labels a field in the details modal (out of
    // scope for this change), so this pins that the *table* column is gone
    // by asserting the label appears only that one remaining time.
    expect(substr_count($response->getContent(), 'Assigned Nurse'))->toBe(1);
});

/**
 * The details modal was redesigned to match the consultation inbox's modal:
 * StatusBadge-driven tokens instead of raw text, an "Additional Information"
 * section, and attachments exposed as routed URLs (see
 * PhysicianController::serializeAttachmentUrls()) instead of raw stored
 * paths — a stored path may be a local-disk fallback that isn't web-reachable
 * on its own, per CLAUDE.md's Cloudinary-fallback note.
 */
it('embeds StatusBadge tokens, additional information, and routed attachment URLs for the modal', function () {
    $physician = activeConsultationPhysician();
    $consultation = activeConsultationFor($physician, [['name' => 'Headache', 'severity' => 2]]);
    $consultation->forceFill([
        'additional_information' => 'Patient also reports dizziness.',
        'file_attachments' => ['consultation_files/scan.png'],
    ])->save();

    $response = $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk();

    // StatusBadge::status('active') / ::priority('Normal') labels.
    $response->assertSee('Active');
    $response->assertSee('Normal Priority');
    $response->assertSee('Patient also reports dizziness.');
    $response->assertSee(json_encode(route('consultation.attachment', [
        'consultation' => $consultation->request_id,
        'file' => 'scan.png',
    ])), false);
});

/*
| Follow-ups are the exception on this page, so only they are marked. The pill
| is asserted as exact markup rather than by the words "Follow-up", which also
| appear in the physician sidebar's "Follow-up Requests" link on every page.
*/

function followUpPillMarkup(): string
{
    return '<span class="inline-flex items-center rounded-full border border-slate-300 px-2 py-0.5 text-[11px] font-medium text-slate-600">Follow-up</span>';
}

it('marks a follow-up consultation in the active consultations list', function () {
    $physician = activeConsultationPhysician();
    activeConsultationFor($physician, [['name' => 'Headache', 'severity' => 2]], 'follow_up');

    $response = $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk();

    expect($response->getContent())->toContain(followUpPillMarkup());
});

it('leaves an initial consultation unmarked in the active consultations list', function () {
    $physician = activeConsultationPhysician();
    activeConsultationFor($physician, [['name' => 'Headache', 'severity' => 2]]);

    $response = $this->actingAs($physician)
        ->get(route('physician.active_consultation', ['physician' => $physician->user_id]))
        ->assertOk();

    expect($response->getContent())->not->toContain(followUpPillMarkup());
});
