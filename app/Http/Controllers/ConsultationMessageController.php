<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;
use App\Models\Message;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConsultationMessageController extends Controller
{
    private const TYPING_TTL_SECONDS = 8;

    public function show(ConsultationSession $session)
    {
        $this->authorize('viewMessaging', $session);

        $session->load([
            'request.patient',
            'request.nurse',
            'physician',
            'messages.sender',
            'messages.attachments',
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
            ->map(function (Message $message) {
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
            })->values();

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
                ]);

                $storedPath = $uploadResult['secure_url'] ?? ($uploadResult['url'] ?? null);
            } catch (\Exception $uploadError) {
                Log::error('Cloudinary Message Attachment Upload Error: ' . $uploadError->getMessage());
            }

            if (!$storedPath) {
                $storedPath = $file->store('message-attachments/' . $session->id, 'public');
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

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
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
                ]);

                $storedPath = $uploadResult['secure_url'] ?? ($uploadResult['url'] ?? null);
            } catch (\Exception $uploadError) {
                Log::error('Cloudinary Prescription Upload Error: ' . $uploadError->getMessage());
            }

            if (!$storedPath) {
                $storedPath = $file->store('consultation-prescriptions/' . $session->id, 'public');
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

    public function complete(ConsultationSession $session): JsonResponse
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

        $session->forceFill([
            'consultation_status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $consultationRequest->update([
            'request_status' => 'completed',
        ]);

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

        return response()->json([
            'peer' => [
                'user_id' => $peerUserId,
                'name' => $peerName !== '' ? $peerName : null,
                'is_typing' => $peerIsTyping,
                'last_seen_at' => $peerLastSeen,
            ],
        ]);
    }

    public function downloadPrescription(ConsultationSession $session)
    {
        $this->authorize('viewMessaging', $session);

        abort_unless($session->prescription_file_path, 404);

        if (is_string($session->prescription_file_path) && str_starts_with($session->prescription_file_path, 'http')) {
            return redirect()->away($session->prescription_file_path);
        }

        return Storage::disk('public')->download(
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

        return Storage::disk('public')->download($attachment->file_path, $attachment->file_name);
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

        Storage::disk('public')->delete($session->prescription_file_path);
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
