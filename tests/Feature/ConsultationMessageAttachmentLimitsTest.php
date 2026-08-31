<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Covers the attachment limits enforced in ConsultationMessageController::store():
| per-type size caps, MP4-only video, a single-video-per-message cap, and the
| 3-attachment ceiling. Existing download/authorization behaviour is already
| covered by AttachmentPrivateStorageTest.php and is not repeated here.
*/

function attachmentLimitScenario(): array
{
    $patient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $physician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

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

    return compact('patient', 'physician', 'session');
}

function sendAttachments(User $actor, ConsultationSession $session, array $attachments)
{
    return test()->actingAs($actor)->post(route('consultations.messaging.store', $session), [
        'message' => 'See attached.',
        'attachments' => $attachments,
    ]);
}

beforeEach(function () {
    Storage::fake('message_attachments');
});

/*
|--------------------------------------------------------------------------
| Images / documents — 10 MB cap
|--------------------------------------------------------------------------
*/

it('accepts an image at or under the 10 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('scan.jpg', 10240, 'image/jpeg'),
    ])->assertOk()->assertJson(['success' => true]);

    expect(MessageAttachment::count())->toBe(1);
});

it('rejects an image over the 10 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('scan.jpg', 10241, 'image/jpeg'),
    ])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'This file is too large. Images and documents must be 10 MB or smaller.']);

    expect(MessageAttachment::count())->toBe(0);
});

it('accepts a PDF at or under the 10 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('labs.pdf', 10240, 'application/pdf'),
    ])->assertOk()->assertJson(['success' => true]);
});

it('rejects a PDF over the 10 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('labs.pdf', 10241, 'application/pdf'),
    ])->assertStatus(422)->assertJson(['success' => false]);
});

it('accepts a DOC and a DOCX at or under the 10 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('notes.doc', 1024, 'application/msword'),
    ])->assertOk()->assertJson(['success' => true]);

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('notes.docx', 1024, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ])->assertOk()->assertJson(['success' => true]);
});

it('rejects a document over the 10 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('notes.docx', 10241, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ])->assertStatus(422)->assertJson(['success' => false]);
});

it('rejects an unsupported file type', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('installer.exe', 100, 'application/x-msdownload'),
    ])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'This file type is not supported.']);
});

/*
|--------------------------------------------------------------------------
| Video — MP4 only, 50 MB cap, 1 per message
|--------------------------------------------------------------------------
*/

it('accepts an MP4 video under the 50 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('checkin.mp4', 2048, 'video/mp4'),
    ])->assertOk()->assertJson(['success' => true]);
});

it('rejects a video over the 50 MB cap', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('checkin.mp4', 51201, 'video/mp4'),
    ])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'This video is too large. Videos must be 50 MB or smaller.']);
});

it('rejects a non-MP4 video type', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('checkin.mov', 2048, 'video/quicktime'),
    ])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'This file type is not supported.']);
});

it('rejects two videos on the same message', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('one.mp4', 1024, 'video/mp4'),
        UploadedFile::fake()->create('two.mp4', 1024, 'video/mp4'),
    ])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'You can attach only 1 video per message.']);

    expect(MessageAttachment::count())->toBe(0);
});

it('accepts one video plus two normal attachments on the same message', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('checkin.mp4', 2048, 'video/mp4'),
        UploadedFile::fake()->create('scan.jpg', 1024, 'image/jpeg'),
        UploadedFile::fake()->create('labs.pdf', 1024, 'application/pdf'),
    ])->assertOk()->assertJson(['success' => true]);

    expect(MessageAttachment::count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Attachment count — 3 per message
|--------------------------------------------------------------------------
*/

it('accepts 3 attachments on one message', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('one.jpg', 512, 'image/jpeg'),
        UploadedFile::fake()->create('two.jpg', 512, 'image/jpeg'),
        UploadedFile::fake()->create('three.jpg', 512, 'image/jpeg'),
    ])->assertOk()->assertJson(['success' => true]);
});

it('rejects 4 attachments on one message', function () {
    ['physician' => $physician, 'session' => $session] = attachmentLimitScenario();

    sendAttachments($physician, $session, [
        UploadedFile::fake()->create('one.jpg', 512, 'image/jpeg'),
        UploadedFile::fake()->create('two.jpg', 512, 'image/jpeg'),
        UploadedFile::fake()->create('three.jpg', 512, 'image/jpeg'),
        UploadedFile::fake()->create('four.jpg', 512, 'image/jpeg'),
    ])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'You can attach up to 3 files per message.']);

    expect(MessageAttachment::count())->toBe(0);
});
