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
| Medical files that fall back to local storage must only ever be reachable
| through a controller action that authorizes first.
|
| The bug these tests exist to prevent: storing them on the "public" disk puts
| them under storage/app/public, which the public/storage symlink exposes, so
| the web server serves the bytes before any PHP — and therefore any
| authorization — runs. A real attachment was confirmed downloadable
| anonymously that way before this change.
|
| "Not on the public disk" is asserted directly, because that is the property
| that makes the URL unreachable; an HTTP-level test cannot see the web
| server's static-file handling from inside the test suite.
*/

function privateStorageScenario(): array
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

function seedPrivateAttachment(ConsultationSession $session, User $sender, string $disk = 'message_attachments'): MessageAttachment
{
    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $sender->user_id,
        'message' => 'Scan attached.',
    ]);

    $path = 'message-attachments/'.$session->id.'/scan.png';
    Storage::disk($disk)->put($path, 'fake-medical-image-bytes');

    return $message->attachments()->create([
        'file_name' => 'scan.png',
        'file_path' => $path,
        'mime_type' => 'image/png',
        'file_size' => 24,
    ]);
}

/*
|--------------------------------------------------------------------------
| Messaging attachments
|--------------------------------------------------------------------------
*/

it('lets the owning patient download a private fallback attachment', function () {
    Storage::fake('message_attachments');
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = privateStorageScenario();

    $attachment = seedPrivateAttachment($session, $physician);

    $this->actingAs($patient)
        ->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertOk();
});

it('lets the assigned physician download a private fallback attachment', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $attachment = seedPrivateAttachment($session, $physician);

    $this->actingAs($physician)
        ->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertOk();
});

it('refuses a private fallback attachment to an unrelated authenticated user', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $attachment = seedPrivateAttachment($session, $physician);

    $outsiderPhysician = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);
    $outsiderPatient = User::factory()->create(['role' => 'patient', 'user_type' => 'student']);
    $nurse = User::factory()->create(['role' => 'nurse', 'user_type' => 'staff']);

    foreach ([$outsiderPhysician, $outsiderPatient, $nurse] as $outsider) {
        $this->actingAs($outsider)
            ->get(route('consultations.messaging.attachments.download', $attachment))
            ->assertForbidden();
    }
});

it('refuses a private fallback attachment to a guest', function () {
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $attachment = seedPrivateAttachment($session, $physician);

    $this->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertRedirect(route('login'));
});

it('stores a fallback attachment on the private disk and never under storage/app/public', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    Cloudinary::shouldReceive('uploadApi->upload')
        ->once()
        ->andThrow(new Exception('Simulated Cloudinary outage'));

    $this->actingAs($physician)
        ->post(route('consultations.messaging.store', $session), [
            'message' => 'Here is the scan.',
            'attachments' => [UploadedFile::fake()->create('scan.jpg', 20, 'image/jpeg')],
        ])
        ->assertOk();

    $attachment = MessageAttachment::firstOrFail();

    Storage::disk('message_attachments')->assertExists($attachment->file_path);
    // The whole point: nothing lands where public/storage could serve it.
    Storage::disk('public')->assertMissing($attachment->file_path);
});

it('leaves cloudinary attachment behaviour unchanged', function () {
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'Cloud-hosted scan.',
    ]);

    $cloudUrl = 'https://res.cloudinary.com/demo/image/upload/v1/message_attachments/example.png';
    $attachment = $message->attachments()->create([
        'file_name' => 'example.png',
        'file_path' => $cloudUrl,
        'mime_type' => 'image/png',
        'file_size' => 1234,
    ]);

    $this->actingAs($physician)
        ->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertRedirect($cloudUrl);
});

/*
|--------------------------------------------------------------------------
| Prescriptions
|--------------------------------------------------------------------------
*/

it('stores a prescription fallback on the private disk, not the public one', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    Cloudinary::shouldReceive('uploadApi->upload')
        ->once()
        ->andThrow(new Exception('Simulated Cloudinary outage'));

    $this->actingAs($physician)
        // All four clinical fields are sent, as the composer always does: the
        // columns are NOT NULL and the controller writes whatever it is given.
        ->post(route('consultations.messaging.clinical_details.update', $session), [
            'assessment' => 'Assessment text',
            'plan' => 'Plan text',
            'recommendations' => 'Recommendations text',
            'diagnosis' => 'Tension headache',
            'prescription' => UploadedFile::fake()->create('rx.pdf', 20, 'application/pdf'),
        ])
        ->assertOk();

    $storedPath = $session->fresh()->prescription_file_path;

    expect($storedPath)->not->toBeNull()->not->toStartWith('http');
    Storage::disk('message_attachments')->assertExists($storedPath);
    Storage::disk('public')->assertMissing($storedPath);
});

it('serves a private prescription to a participant and refuses everyone else', function () {
    Storage::fake('message_attachments');
    ['patient' => $patient, 'physician' => $physician, 'session' => $session] = privateStorageScenario();

    $path = 'consultation-prescriptions/'.$session->id.'/rx.pdf';
    Storage::disk('message_attachments')->put($path, 'fake-prescription-bytes');

    $session->forceFill([
        'prescription_file_name' => 'rx.pdf',
        'prescription_file_path' => $path,
        'prescription_mime_type' => 'application/pdf',
        'prescription_file_size' => 22,
    ])->save();

    $this->actingAs($patient)
        ->get(route('consultations.messaging.prescription.download', $session))
        ->assertOk();

    $this->actingAs($physician)
        ->get(route('consultations.messaging.prescription.download', $session))
        ->assertOk();

    $outsider = User::factory()->create(['role' => 'physician', 'user_type' => 'staff']);
    $this->actingAs($outsider)
        ->get(route('consultations.messaging.prescription.download', $session))
        ->assertForbidden();
});

it('refuses a private prescription to a guest', function () {
    Storage::fake('message_attachments');
    ['session' => $session] = privateStorageScenario();

    $path = 'consultation-prescriptions/'.$session->id.'/rx.pdf';
    Storage::disk('message_attachments')->put($path, 'fake-prescription-bytes');

    $session->forceFill([
        'prescription_file_name' => 'rx.pdf',
        'prescription_file_path' => $path,
        'prescription_mime_type' => 'application/pdf',
        'prescription_file_size' => 22,
    ])->save();

    $this->get(route('consultations.messaging.prescription.download', $session))
        ->assertRedirect(route('login'));
});

it('keeps the private disk outside the public web root and unserveable by the framework', function () {
    // Two independent guarantees, both configuration-level, both of which the
    // exploit depended on being false.
    $privateRoot = config('filesystems.disks.message_attachments.root');
    $publicRoot = config('filesystems.disks.public.root');

    expect($privateRoot)->not->toBeNull();
    expect(str_starts_with($privateRoot, $publicRoot))->toBeFalse();

    // serve => true would make Laravel register GET|PUT /storage/{path} routes
    // for this disk, replacing application authorization with signed URLs.
    expect(config('filesystems.disks.message_attachments.serve'))->toBeFalse();
    expect(config('filesystems.disks.message_attachments.url'))->toBeNull();
    expect(config('filesystems.disks.message_attachments.visibility'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Migration command
|--------------------------------------------------------------------------
*/

it('migrates a public attachment to the private disk, preserving bytes and path', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    // A legacy row: the file still sits on the exposed public disk.
    $attachment = seedPrivateAttachment($session, $physician, 'public');
    $originalPath = $attachment->file_path;
    $originalBytes = Storage::disk('public')->get($originalPath);

    $this->artisan('attachments:move-to-private')->assertSuccessful();

    Storage::disk('message_attachments')->assertExists($originalPath);
    expect(Storage::disk('message_attachments')->get($originalPath))->toBe($originalBytes);
    expect(Storage::disk('message_attachments')->size($originalPath))->toBe(strlen($originalBytes));

    // The database path is disk-relative and unchanged by design.
    expect($attachment->fresh()->file_path)->toBe($originalPath);

    // The exposed copy is gone, which is what closes the hole.
    Storage::disk('public')->assertMissing($originalPath);

    // And the file is still downloadable through the authorized endpoint.
    $this->actingAs($physician)
        ->get(route('consultations.messaging.attachments.download', $attachment))
        ->assertOk();
});

it('is safe to run again and leaves cloudinary rows untouched', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $attachment = seedPrivateAttachment($session, $physician, 'public');

    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'Cloud-hosted scan.',
    ]);
    $cloudUrl = 'https://res.cloudinary.com/demo/image/upload/v1/message_attachments/example.png';
    $cloudAttachment = $message->attachments()->create([
        'file_name' => 'example.png',
        'file_path' => $cloudUrl,
        'mime_type' => 'image/png',
        'file_size' => 1234,
    ]);

    $this->artisan('attachments:move-to-private')->assertSuccessful();
    // Second run: the file is already private and the public copy is gone.
    $this->artisan('attachments:move-to-private')->assertSuccessful();

    Storage::disk('message_attachments')->assertExists($attachment->fresh()->file_path);
    expect($cloudAttachment->fresh()->file_path)->toBe($cloudUrl);
});

it('reports a missing source file instead of silently passing over it', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $message = Message::create([
        'consultation_id' => $session->id,
        'sender_id' => $physician->user_id,
        'message' => 'Scan attached.',
    ]);

    // Row points at bytes that exist on neither disk.
    $orphan = $message->attachments()->create([
        'file_name' => 'gone.png',
        'file_path' => 'message-attachments/'.$session->id.'/gone.png',
        'mime_type' => 'image/png',
        'file_size' => 10,
    ]);

    $this->artisan('attachments:move-to-private')
        ->expectsOutputToContain('source file not found on either disk')
        ->assertFailed();

    // The row is left exactly as it was for a human to investigate.
    expect($orphan->fresh()->file_path)->toBe('message-attachments/'.$session->id.'/gone.png');
});

it('does not touch anything during a dry run', function () {
    Storage::fake('public');
    Storage::fake('message_attachments');
    ['physician' => $physician, 'session' => $session] = privateStorageScenario();

    $attachment = seedPrivateAttachment($session, $physician, 'public');

    $this->artisan('attachments:move-to-private', ['--dry-run' => true])->assertSuccessful();

    Storage::disk('public')->assertExists($attachment->file_path);
    Storage::disk('message_attachments')->assertMissing($attachment->file_path);
});
