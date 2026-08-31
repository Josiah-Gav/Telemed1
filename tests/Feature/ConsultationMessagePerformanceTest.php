<?php

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Covers the behaviour the messaging performance work depends on:
|
| - store() returns the created message so the client does not have to refetch
|   the whole conversation, and it must be the same shape index() returns.
| - a Cloudinary failure still falls back to private local storage.
| - attachment downloads stay authorized, and may only be cached privately.
|
| These assert application behaviour, not timings, so they stay meaningful
| after the profiling that motivated them is long finished.
*/

function messagePerfScenario(): array
{
    $patient = User::factory()->create([
        'role' => 'patient',
        'user_type' => 'student',
        'first_name' => 'Rosa',
        'last_name' => 'Delacruz',
    ]);

    $physician = User::factory()->create([
        'role' => 'physician',
        'user_type' => 'staff',
        'first_name' => 'Mario',
        'last_name' => 'Santos',
    ]);

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

it('returns the created plain-text message in the same shape index() returns', function () {
    ['physician' => $physician, 'session' => $session] = messagePerfScenario();

    $created = $this->actingAs($physician)
        ->postJson(route('consultations.messaging.store', $session), [
            'message' => 'How are you feeling today?',
        ])
        ->assertOk()
        ->assertJson([
            'success' => true,
            // The human-readable status string must keep its existing meaning:
            // the frontend still reads it on the failure path.
            'message' => 'Message sent successfully.',
        ])
        ->json('created_message');

    expect($created)->toHaveKeys([
        'message_id', 'sender_id', 'sender_name', 'message', 'read_at', 'created_at', 'attachments',
    ]);
    expect($created['message'])->toBe('How are you feeling today?');
    expect($created['sender_id'])->toBe($physician->user_id);
    expect($created['sender_name'])->toBe('Mario Santos');
    expect($created['attachments'])->toBe([]);

    // Appending this to the conversation client-side must be indistinguishable
    // from what the next poll returns, or the message would visibly change.
    $fromIndex = $this->actingAs($physician)
        ->getJson(route('consultations.messaging.index', $session))
        ->assertOk()
        ->json('messages.0');

    expect($created)->toEqual($fromIndex);
});

it('returns attachments on the created message with working download urls', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = messagePerfScenario();

    // No Cloudinary credentials are exercised here: the upload throws and the
    // local-disk fallback stores the file, which is the path under test.
    Cloudinary::shouldReceive('uploadApi->upload')
        ->once()
        ->andThrow(new Exception('Simulated Cloudinary outage'));

    $created = $this->actingAs($physician)
        ->post(route('consultations.messaging.store', $session), [
            'message' => 'Here is the scan.',
            // create() rather than image(): the GD extension is not enabled in
            // this environment, and only the mime type matters here.
            'attachments' => [UploadedFile::fake()->create('scan.jpg', 20, 'image/jpeg')],
        ])
        ->assertOk()
        ->json('created_message');

    expect($created['attachments'])->toHaveCount(1);
    expect($created['attachments'][0])->toHaveKeys([
        'attachment_id', 'file_name', 'mime_type', 'file_size', 'download_url',
    ]);
    expect($created['attachments'][0]['file_name'])->toBe('scan.jpg');
    expect($created['attachments'][0]['mime_type'])->toStartWith('image/');

    // The fallback wrote a local relative path, not a Cloudinary URL, onto the
    // private disk (see AttachmentPrivateStorageTest for the security property).
    $attachment = MessageAttachment::firstOrFail();
    expect($attachment->file_path)->not->toStartWith('http');
    Storage::disk('message_attachments')->assertExists($attachment->file_path);

    // ...and that URL still serves the file to an authorized participant.
    $this->actingAs($physician)->get($created['attachments'][0]['download_url'])->assertOk();
});

it('lets a browser cache an attachment privately but never publicly', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = messagePerfScenario();

    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'Scan attached.',
    ]);

    Storage::disk('message_attachments')->put('message-attachments/scan.jpg', 'fake-bytes');
    $attachment = $message->attachments()->create([
        'file_name' => 'scan.jpg',
        'file_path' => 'message-attachments/scan.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 10,
    ]);

    $cacheControl = $this->actingAs($physician)
        ->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertOk()
        ->headers->get('Cache-Control');

    // Patient data must never land in a shared or proxy cache.
    expect($cacheControl)->toContain('private')
        ->not->toContain('public');
    expect($cacheControl)->toMatch('/max-age=[1-9]\d*/');
});

it('still refuses an attachment download to someone outside the consultation', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = messagePerfScenario();

    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'Scan attached.',
    ]);

    Storage::disk('message_attachments')->put('message-attachments/scan.jpg', 'fake-bytes');
    $attachment = $message->attachments()->create([
        'file_name' => 'scan.jpg',
        'file_path' => 'message-attachments/scan.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 10,
    ]);

    $outsider = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);

    // Caching headers must not have widened who can fetch the file.
    $this->actingAs($outsider)
        ->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertForbidden();
});

it('still refuses an attachment download to a guest', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = messagePerfScenario();

    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'Scan attached.',
    ]);

    Storage::disk('message_attachments')->put('message-attachments/scan.jpg', 'fake-bytes');
    $attachment = $message->attachments()->create([
        'file_name' => 'scan.jpg',
        'file_path' => 'message-attachments/scan.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 10,
    ]);

    $this->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertRedirect(route('login'));
});

it('rejects an empty message exactly as before', function () {
    ['physician' => $physician, 'session' => $session] = messagePerfScenario();

    // The existing error contract is what the frontend reads on failure, so it
    // must survive the added created_message key.
    $this->actingAs($physician)
        ->postJson(route('consultations.messaging.store', $session), ['message' => '   '])
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Provide a message or at least one attachment.',
        ]);
});

it('bounds the cloudinary upload timeout well below the sdk default', function () {
    // The SDK default is 60s (ApiConfig::DEFAULT_TIMEOUT); a synchronous upload
    // holding a PHP worker that long is what this configuration exists to stop.
    expect(config('cloudinary.upload_timeout'))->toBeNumeric()
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(15);

    // Publishing a partial config must not shadow the package's own defaults.
    expect(config('cloudinary'))->toHaveKey('cloud_url');
});
