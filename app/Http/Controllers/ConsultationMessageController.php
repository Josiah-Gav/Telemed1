<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;
use App\Models\Message;
use App\Models\User;
use App\Enums\NotificationType;
use App\Services\ConsultationVideoService;
use App\Services\NotificationService;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConsultationMessageController extends Controller
{
    private const TYPING_TTL_SECONDS = 8;

    /**
     * Disk holding message attachments and prescriptions that fell back to
     * local storage because Cloudinary was unavailable. It is deliberately not
     * the public disk: files there are served straight off the filesystem by
     * the web server through the public/storage symlink, which would bypass
     * the authorization every download action in this controller performs.
     * See config/filesystems.php for why it is also not the "local" disk.
     */
    private const PRIVATE_DISK = 'message_attachments';

    /**
     * How long a browser may reuse an already-downloaded message attachment.
     * Only applied to attachments, never to prescriptions: a prescription is
     * served from a per-session URL whose file can be replaced in place, so
     * caching it would risk showing a superseded prescription.
     */
    private const ATTACHMENT_CACHE_SECONDS = 3600;

    public function show(ConsultationSession $session)
    {
        $this->authorize('viewMessaging', $session);

        // Messages are not eager-loaded here on purpose: the view never reads
        // $session->messages, it fetches the conversation over AJAX from
        // index() once Alpine boots. Loading them here only paid for rows that
        // were immediately thrown away.
        $session->load([
            'request.patient',
            'request.nurse',
            'physician',
        ]);

        return view('consultations.messaging', [
            'session' => $session,
        ]);
    }

    public function index(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $currentUser = Auth::user();
        $this->touchLastSeen((int) $session->id, (int) $currentUser->user_id);

        $messages = $session->messages()
            ->with(['sender', 'attachments'])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Message $message) => $this->serializeMessage($message))
            ->values();

        return response()->json([
            'messages' => $messages,
        ]);
    }

    public function store(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorize('sendMessage', $session);

        $validated = $request->validate([
            'message' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240',
        ]);

        $body = trim((string) ($validated['message'] ?? ''));
        $files = $request->file('attachments', []);

        if ($body === '' && empty($files)) {
            return response()->json([
                'success' => false,
                'message' => 'Provide a message or at least one attachment.',
            ], 422);
        }

        $message = Message::create([
            'consultation_id' => $session->id,
            'sender_id' => Auth::user()->user_id,
            'message' => $body !== '' ? $body : null,
        ]);

        foreach ($files as $file) {
            $storedPath = null;

            try {
                $uploadResult = Cloudinary::uploadApi()->upload($file->getRealPath(), [
                    'folder' => 'message_attachments',
                    'resource_type' => 'auto',
                    // Bounds how long a stalled Cloudinary call may hold this PHP
                    // worker before the local-disk fallback below takes over. The
                    // SDK's own default is 60 seconds, which is long enough for one
                    // bad upload to block every other request on the server. These
                    // two keys are not upload parameters — buildUploadParams()
                    // whitelists those, so they never reach the request signature;
                    // the SDK forwards them to its HTTP client instead.
                    'timeout' => config('cloudinary.upload_timeout'),
                    'connect_timeout' => config('cloudinary.upload_timeout'),
                ]);

                $storedPath = $uploadResult['secure_url'] ?? ($uploadResult['url'] ?? null);
            } catch (\Exception $uploadError) {
                Log::error('Cloudinary Message Attachment Upload Error: ' . $uploadError->getMessage());
            }

            if (!$storedPath) {
                // Private disk, not 'public': a fallback attachment is patient
                // data and must only be reachable through downloadAttachment()
                // below, which authorizes first. The relative path stored in the
                // database is unchanged — only the disk it resolves against.
                $storedPath = $file->store('message-attachments/' . $session->id, self::PRIVATE_DISK);
            }

            $message->attachments()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $storedPath,
                'mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
                'file_size' => $file->getSize() ?? 0,
            ]);
        }

        $this->setTyping((int) $session->id, (int) Auth::user()->user_id, false);
        $this->touchLastSeen((int) $session->id, (int) Auth::user()->user_id);

        $this->notifyMessageRecipients($session, $body !== '', !empty($files));

        // The sender is already known, so it is set rather than re-queried;
        // attachments must be re-read because the relation was resolved before
        // the rows above were created.
        $message->setRelation('sender', Auth::user());
        $message->load('attachments');

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            // Returned under its own key so the existing string 'message' key
            // keeps its meaning for the error path the frontend already reads.
            // It lets the client show the sent message without refetching the
            // whole conversation; polling still reconciles from index().
            'created_message' => $this->serializeMessage($message),
        ]);
    }

    public function updateClinicalDetails(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        abort_if($session->consultation_status !== 'active', Response::HTTP_FORBIDDEN, 'Clinical details can only be updated while the consultation is active.');

        abort_unless(
            Auth::user()->role === 'physician' && (int) $session->physician_id === (int) Auth::user()->user_id,
            403,
            'Only the assigned physician can update clinical details.'
        );

        $validated = $request->validate([
            'assessment' => 'nullable|string|max:10000',
            'plan' => 'nullable|string|max:10000',
            'recommendations' => 'nullable|string|max:10000',
            'diagnosis' => 'nullable|string|max:255',
            'prescription' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
            'remove_prescription' => 'nullable|boolean',
        ]);

        $session->fill([
            'assessment' => $validated['assessment'] ?? null,
            'plan' => $validated['plan'] ?? null,
            'recommendations' => $validated['recommendations'] ?? null,
            'diagnosis' => $validated['diagnosis'] ?? null,
        ]);

        $removePrescription = (bool) ($validated['remove_prescription'] ?? false);

        if ($removePrescription && !$request->hasFile('prescription')) {
            $this->deletePrescriptionFile($session);
            $session->forceFill([
                'prescription_file_name' => null,
                'prescription_file_path' => null,
                'prescription_mime_type' => null,
                'prescription_file_size' => null,
            ]);
        }

        if ($request->hasFile('prescription')) {
            $file = $request->file('prescription');
            $storedPath = null;

            try {
                $uploadResult = Cloudinary::uploadApi()->upload($file->getRealPath(), [
                    'folder' => 'consultation_prescriptions',
                    'resource_type' => 'auto',
                    // Same bound as the message attachment upload above.
                    'timeout' => config('cloudinary.upload_timeout'),
                    'connect_timeout' => config('cloudinary.upload_timeout'),
                ]);

                $storedPath = $uploadResult['secure_url'] ?? ($uploadResult['url'] ?? null);
            } catch (\Exception $uploadError) {
                Log::error('Cloudinary Prescription Upload Error: ' . $uploadError->getMessage());
            }

            if (!$storedPath) {
                // Same private disk as message attachments, in its own directory.
                $storedPath = $file->store('consultation-prescriptions/' . $session->id, self::PRIVATE_DISK);
            }

            $this->deletePrescriptionFile($session);

            $session->forceFill([
                'prescription_file_name' => $file->getClientOriginalName(),
                'prescription_file_path' => $storedPath,
                'prescription_mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
                'prescription_file_size' => $file->getSize() ?? 0,
            ]);
        }

        $session->save();

        return response()->json([
            'success' => true,
            'message' => 'Clinical details updated successfully.',
            'clinical_details' => $this->buildClinicalDetailsPayload($session->fresh()),
        ]);
    }

    public function complete(ConsultationSession $session, ConsultationVideoService $videoSessions): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        abort_unless(
            Auth::user()->role === 'physician' && (int) $session->physician_id === (int) Auth::user()->user_id,
            403,
            'Only the assigned physician can complete this consultation.'
        );

        if ($session->consultation_status === 'completed' && optional($session->request)->request_status === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Consultation is already completed.',
                'session_status' => $session->consultation_status,
                'request_status' => optional($session->request)->request_status,
                'completed_at' => optional($session->completed_at)?->toIso8601String(),
            ]);
        }

        abort_if($session->consultation_status !== 'active', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only active consultations can be completed.');

        $consultationRequest = $session->request;
        abort_unless($consultationRequest, 404);

        DB::transaction(function () use ($session, $videoSessions) {
            $lockedSession = ConsultationSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            abort_unless($lockedSession, 404);

            $lockedRequest = $lockedSession->request()->lockForUpdate()->first();
            abort_unless($lockedRequest, 404);

            $lockedSession->forceFill([
                'consultation_status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $lockedRequest->update([
                'request_status' => 'completed',
            ]);

            if ($lockedSession->slot_id) {
                $lockedSlot = $lockedSession->slot()->lockForUpdate()->first();

                if ($lockedSlot && in_array($lockedSlot->status, ['booked', 'missed'], true)) {
                    $lockedSlot->update([
                        'status' => 'completed',
                    ]);
                }
            }

            // Close any running video call last, inside this same transaction, so the
            // room can never outlive the consultation and a rollback leaves it open.
            // Only rows with a null ended_at are touched, so historical sessions keep
            // their original timestamp.
            $videoSessions->end($lockedSession);
        });

        $session->refresh();
        $consultationRequest = $session->request;

        if ($consultationRequest) {
            NotificationService::sendUnique(
                $consultationRequest->patient_id,
                NotificationType::CONSULTATION_COMPLETED,
                'Consultation Completed',
                'Your consultation has been completed. You can view the summary and prescription in your consultation history.',
                [
                    'consultation_id' => $consultationRequest->request_id,
                    'request_id' => $consultationRequest->request_id,
                    'session_id' => $session->id,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Consultation completed successfully.',
            'session_status' => $session->consultation_status,
            'request_status' => $consultationRequest->request_status,
            'completed_at' => optional($session->completed_at)?->toIso8601String(),
        ]);
    }

    public function markRead(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $currentUserId = (int) Auth::user()->user_id;
        $this->touchLastSeen((int) $session->id, $currentUserId);

        $session->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $currentUserId)
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
        ]);
    }

    public function unreadCounts(): JsonResponse
    {
        $currentUser = Auth::user();
        $currentUserId = (int) $currentUser->user_id;

        $sessionIds = ConsultationSession::query()
            ->where('consultation_status', 'active')
            ->where(function ($query) use ($currentUser, $currentUserId) {
                if ($currentUser->role === 'patient') {
                    $query->whereHas('request', function ($requestQuery) use ($currentUserId) {
                        $requestQuery
                            ->where('patient_id', $currentUserId)
                            ->where('request_status', 'active');
                    });
                }

                if ($currentUser->role === 'physician') {
                    $query
                        ->where('physician_id', $currentUserId)
                        ->whereHas('request', function ($requestQuery) {
                            $requestQuery->where('request_status', 'active');
                        });
                }
            })
            ->pluck('id')
            ->all();

        if (empty($sessionIds)) {
            return response()->json([
                'counts' => [],
                'total_unread' => 0,
            ]);
        }

        $counts = Message::query()
            ->whereIn('consultation_id', $sessionIds)
            ->whereNull('read_at')
            ->where('sender_id', '!=', $currentUserId)
            ->selectRaw('consultation_id, COUNT(*) as unread_count')
            ->groupBy('consultation_id')
            ->pluck('unread_count', 'consultation_id');

        $normalizedCounts = [];
        $totalUnread = 0;

        foreach ($sessionIds as $sessionId) {
            $count = (int) ($counts[$sessionId] ?? 0);
            $normalizedCounts[(string) $sessionId] = $count;
            $totalUnread += $count;
        }

        return response()->json([
            'counts' => $normalizedCounts,
            'total_unread' => $totalUnread,
        ]);
    }

    public function typing(Request $request, ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $validated = $request->validate([
            'is_typing' => 'required|boolean',
        ]);

        $userId = (int) Auth::user()->user_id;
        $isTyping = (bool) $validated['is_typing'];

        $this->setTyping((int) $session->id, $userId, $isTyping);
        $this->touchLastSeen((int) $session->id, $userId);

        return response()->json([
            'success' => true,
        ]);
    }

    public function presence(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        $currentUser = Auth::user();
        $currentUserId = (int) $currentUser->user_id;
        $this->touchLastSeen((int) $session->id, $currentUserId);

        $session->loadMissing(['request.patient', 'physician']);

        $peerUser = null;
        if ($currentUser->role === 'patient') {
            $peerUser = $session->physician;
        } elseif ($currentUser->role === 'physician') {
            $peerUser = optional($session->request)->patient;
        }

        $peerUserId = $peerUser ? (int) $peerUser->user_id : null;
        $peerName = trim((optional($peerUser)->first_name ?? '') . ' ' . (optional($peerUser)->last_name ?? ''));
        $peerIsTyping = $peerUserId
            ? Cache::has($this->typingKey((int) $session->id, $peerUserId))
            : false;
        $peerLastSeen = $peerUserId
            ? Cache::get($this->lastSeenKey((int) $session->id, $peerUserId))
            : null;
        $peerIsOnline = $peerUser
            && $peerUser->online_status === 'online'
            && $peerUser->last_seen_at
            && $peerUser->last_seen_at->gt(now()->subMinutes(2));

        return response()->json([
            'peer' => [
                'user_id' => $peerUserId,
                'name' => $peerName !== '' ? $peerName : null,
                'is_typing' => $peerIsTyping,
                'is_online' => (bool) $peerIsOnline,
                'last_seen_at' => $peerLastSeen,
            ],
            'video' => [
                // Deliberately just a boolean: no room_name, jwt, domain, or any other
                // Jitsi identifier belongs on a passive polling endpoint. Those are only
                // ever issued by the authorized POST /video/join request. Gated on
                // consultation_status === 'active' as well as row existence, so a
                // completed consultation reports false even if a stale open row exists.
                'active' => $session->consultation_status === 'active'
                    && $session->activeVideoSession()->exists(),
            ],
        ]);
    }

    public function markOffline(ConsultationSession $session): JsonResponse
    {
        $this->authorize('viewMessaging', $session);

        User::where('user_id', (int) Auth::user()->user_id)
            ->update([
                'online_status' => 'offline',
            ]);

        return response()->json([
            'success' => true,
        ]);
    }

    public function downloadPrescription(ConsultationSession $session)
    {
        $this->authorize('viewMessaging', $session);

        abort_unless($session->prescription_file_path, 404);

        if (is_string($session->prescription_file_path) && str_starts_with($session->prescription_file_path, 'http')) {
            return redirect()->away($session->prescription_file_path);
        }

        // Authorization above has already run; only then is the private file
        // read. Nothing here ever produces a URL to the file itself.
        return Storage::disk(self::PRIVATE_DISK)->download(
            $session->prescription_file_path,
            $session->prescription_file_name ?? 'prescription'
        );
    }

    public function downloadAttachment(\App\Models\MessageAttachment $attachment)
    {
        $message = $attachment->message;
        $session = optional($message)->consultation;

        abort_unless($session, 404);
        $this->authorize('viewMessaging', $session);

        if (is_string($attachment->file_path) && str_starts_with($attachment->file_path, 'http')) {
            return redirect()->away($attachment->file_path);
        }

        // A stored attachment is immutable: replacing a file means a new row and
        // therefore a new URL, so the bytes behind this URL never change and the
        // browser can safely reuse them instead of re-downloading on every render.
        // Deliberately 'private', never 'public': this is patient data and must
        // not sit in a shared or proxy cache. Authorization above still runs on
        // every request that actually reaches the server, and the window is kept
        // short so a revoked viewer's own cached copy expires quickly.
        return Storage::disk(self::PRIVATE_DISK)->download($attachment->file_path, $attachment->file_name, [
            'Cache-Control' => 'private, max-age=' . self::ATTACHMENT_CACHE_SECONDS,
        ]);
    }

    /**
     * The single shape a message takes on the wire. index() and store() both
     * use it so a message the client appends after sending is byte-identical
     * to the same message when polling later refetches it.
     */
    private function serializeMessage(Message $message): array
    {
        return [
            'message_id' => $message->message_id,
            'sender_id' => $message->sender_id,
            'sender_name' => trim((optional($message->sender)->first_name ?? '') . ' ' . (optional($message->sender)->last_name ?? '')),
            'message' => $message->message,
            'read_at' => optional($message->read_at)?->toIso8601String(),
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'attachments' => $message->attachments->map(function ($attachment) {
                return [
                    'attachment_id' => $attachment->attachment_id,
                    'file_name' => $attachment->file_name,
                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,
                    'download_url' => route('consultations.messaging.attachments.download', $attachment),
                ];
            })->values(),
        ];
    }

    /**
     * Notify the other participant in a consultation session when a message
     * or attachment is sent.
     */
    private function notifyMessageRecipients(ConsultationSession $session, bool $hasMessage, bool $hasAttachments): void
    {
        if (!$hasMessage && !$hasAttachments) {
            return;
        }

        $session->loadMissing(['request.patient', 'physician']);

        $currentUser = Auth::user();
        $currentUserId = (int) $currentUser->user_id;

        $recipientUser = null;
        if ($currentUser->role === 'patient') {
            $recipientUser = $session->physician;
        } elseif ($currentUser->role === 'physician') {
            $recipientUser = optional($session->request)->patient;
        }

        if (!$recipientUser) {
            return;
        }

        $recipientId = (int) $recipientUser->user_id;

        if ($hasMessage) {
            NotificationService::send(
                $recipientId,
                NotificationType::NEW_MESSAGE,
                'New Message',
                'You received a new message for consultation #' . $session->request_id . '.',
                [
                    'consultation_id' => $session->request_id,
                    'request_id' => $session->request_id,
                    'session_id' => $session->id,
                ]
            );
        }

        if ($hasAttachments) {
            NotificationService::send(
                $recipientId,
                NotificationType::NEW_ATTACHMENT,
                'New Attachment',
                'A new attachment was uploaded to consultation #' . $session->request_id . '.',
                [
                    'consultation_id' => $session->request_id,
                    'request_id' => $session->request_id,
                    'session_id' => $session->id,
                ]
            );
        }
    }

    private function setTyping(int $sessionId, int $userId, bool $isTyping): void
    {
        $cacheKey = $this->typingKey($sessionId, $userId);

        if ($isTyping) {
            Cache::put($cacheKey, true, now()->addSeconds(self::TYPING_TTL_SECONDS));
            return;
        }

        Cache::forget($cacheKey);
    }

    private function touchLastSeen(int $sessionId, int $userId): void
    {
        Cache::put($this->lastSeenKey($sessionId, $userId), now()->toIso8601String(), now()->addHours(24));

        User::where('user_id', $userId)
            ->update([
                'online_status' => 'online',
                'last_seen_at' => now(),
            ]);
    }

    private function buildClinicalDetailsPayload(ConsultationSession $session): array
    {
        return [
            'assessment' => $session->assessment,
            'plan' => $session->plan,
            'recommendations' => $session->recommendations,
            'diagnosis' => $session->diagnosis,
            'status' => $session->consultation_status,
            'completed_at' => optional($session->completed_at)?->toIso8601String(),
            'prescription' => [
                'file_name' => $session->prescription_file_name,
                'file_size' => $session->prescription_file_size,
                'download_url' => $session->prescription_file_path
                    ? route('consultations.messaging.prescription.download', $session)
                    : null,
            ],
        ];
    }

    private function deletePrescriptionFile(ConsultationSession $session): void
    {
        if (!$session->prescription_file_path || str_starts_with((string) $session->prescription_file_path, 'http')) {
            return;
        }

        Storage::disk(self::PRIVATE_DISK)->delete($session->prescription_file_path);
    }

    private function typingKey(int $sessionId, int $userId): string
    {
        return 'consultation:' . $sessionId . ':typing:' . $userId;
    }

    private function lastSeenKey(int $sessionId, int $userId): string
    {
        return 'consultation:' . $sessionId . ':last_seen:' . $userId;
    }
}
