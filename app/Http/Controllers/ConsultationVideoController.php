<?php

namespace App\Http\Controllers;

use App\Models\ConsultationSession;
use App\Models\ConsultationVideoSession;
use App\Models\User;
use App\Services\ConsultationVideoService;
use App\Services\JitsiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ConsultationVideoController extends Controller
{
    public function __construct(
        private readonly ConsultationVideoService $videoSessions,
        private readonly JitsiService $jitsi,
    ) {}

    /**
     * Physician-only: open the video consultation, or rejoin the one already running.
     */
    public function start(ConsultationSession $session): JsonResponse
    {
        $this->authorize('startVideo', $session);

        try {
            $videoSession = $this->videoSessions->startForPhysician($session, Auth::user());
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($this->joinPayload($videoSession, Auth::user(), $session));
    }

    /**
     * Join a video consultation the physician has already started.
     *
     * Never creates one: if no room is running the request is refused, which is what
     * keeps room creation physician-initiated even though patients call this endpoint.
     */
    public function join(ConsultationSession $session): JsonResponse
    {
        $this->authorize('joinVideo', $session);

        $videoSession = $this->videoSessions->activeFor($session);

        if (! $videoSession) {
            return response()->json([
                'success' => false,
                'message' => 'The physician has not started the video consultation yet.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json($this->joinPayload($videoSession, Auth::user(), $session));
    }

    /**
     * Physician-only: close the running video consultation.
     */
    public function end(ConsultationSession $session): JsonResponse
    {
        $this->authorize('startVideo', $session);

        return response()->json([
            'success' => true,
            'ended' => $this->videoSessions->end($session),
        ]);
    }

    /**
     * Everything the browser needs to boot the Jitsi IFrame, and nothing more.
     * No app id, no api key id, no key material.
     */
    private function joinPayload(
        ConsultationVideoSession $videoSession,
        User $user,
        ConsultationSession $session
    ): array {
        $isModerator = $this->isAssignedPhysician($user, $session);
        $displayName = $this->displayNameFor($user);

        return [
            'success' => true,
            'domain' => $this->jitsi->domain(),
            // The IFrame API's roomName option: the "{app_id}/{room}" form.
            'room_name' => $this->jitsi->iframeRoomName($videoSession->room_name),
            'jwt' => $this->jitsi->issueToken($videoSession->room_name, $displayName, $isModerator),
            'display_name' => $displayName,
            'is_moderator' => $isModerator,
        ];
    }

    private function isAssignedPhysician(User $user, ConsultationSession $session): bool
    {
        return $user->role === 'physician'
            && (int) $session->physician_id === (int) $user->user_id;
    }

    private function displayNameFor(User $user): string
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return $name !== '' ? $name : 'Participant';
    }
}
