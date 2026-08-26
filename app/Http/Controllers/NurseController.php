<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Consultation;
use App\Models\FollowUpRequest;
use App\Models\User;
use App\Enums\NotificationType;
use App\Services\ConsultationOwnershipService;
use App\Services\DashboardAnalyticsService;
use App\Services\NotificationService;
use App\Support\DateRange;

class NurseController extends Controller
{
    public function __construct(
        private readonly ConsultationOwnershipService $ownershipService,
        private readonly DashboardAnalyticsService $analyticsService,
    ) {
        $this->middleware('auth');
    }


    private function authorizeNurse(User $nurse)
    {
        if (Auth::user()->role !== 'nurse' || Auth::id() !== $nurse->user_id) {
            abort(403, 'Unauthorized access.');
        }
    }

    public function dashboard(User $nurse, Request $request)
    {
        $this->authorizeNurse($nurse);

        $dateRange = DateRange::fromInput(
            $request->query('range'),
            $request->query('start'),
            $request->query('end'),
            'last_30_days',
        );

        return view('nurse.dashboard', [
            'nurse' => $nurse,
            'analytics' => $this->analyticsService->forNurse($nurse, $dateRange),
            'dateRange' => $dateRange,
        ]);
    }

    public function consultationInbox(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $inboxData = $this->getConsultationInboxData($nurse);

        return view('nurse.consultation_inbox', [
            'nurse' => $nurse,
            'pendingRequests' => $inboxData['pendingRequests'],
            'assignedToCurrentNurse' => $inboxData['assignedToCurrentNurse'],
            'assignedToOtherNurses' => $inboxData['assignedToOtherNurses'],
        ]);
    }

    public function consultationInboxRefresh(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $inboxData = $this->getConsultationInboxData($nurse);

        return response()->json([
            'pendingRequests' => $this->serializeConsultations($inboxData['pendingRequests']),
            'assignedToCurrentNurse' => $this->serializeConsultations($inboxData['assignedToCurrentNurse']),
            'assignedToOtherNurses' => $this->serializeConsultations($inboxData['assignedToOtherNurses']),
        ]);
    }

    public function followUpRequests(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $pendingRequests = FollowUpRequest::with(['patient', 'consultation.request', 'consultation.physician'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return view('nurse.follow_up_requests', [
            'nurse' => $nurse,
            'pendingRequests' => $pendingRequests,
        ]);
    }

    public function forwardFollowUpRequest(Request $request, User $nurse, FollowUpRequest $followUpRequest)
    {
        $this->authorizeNurse($nurse);

        $validated = $request->validate([
            'decision_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $followUpRequest = $this->ownershipService->forwardFollowUpByNurse(
                (int) $followUpRequest->id,
                (int) Auth::id(),
                $validated['decision_notes'] ?? null
            );
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['follow_up_request' => $e->getMessage()]);
        }

        NotificationService::sendToRole(
            'physician',
            NotificationType::FOLLOW_UP_SUBMITTED,
            'Follow-up Request',
            'A patient submitted a follow-up request that requires your decision.',
            [
                'follow_up_request_id' => $followUpRequest->id,
                'consultation_id' => $followUpRequest->consultation_id,
                'patient_id' => $followUpRequest->patient_id,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Follow-up request forwarded to physician review.',
            ]);
        }

        return back()->with('status', 'Follow-up request forwarded to physician review.');
    }

    public function rejectFollowUpRequest(Request $request, User $nurse, FollowUpRequest $followUpRequest)
    {
        $this->authorizeNurse($nurse);

        $validated = $request->validate([
            'decision_notes' => 'required|string|max:2000',
        ]);

        try {
            $followUpRequest = $this->ownershipService->rejectFollowUpByNurse(
                (int) $followUpRequest->id,
                (int) Auth::id(),
                (string) $validated['decision_notes']
            );
        } catch (\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['follow_up_request' => $e->getMessage()]);
        }

        NotificationService::send(
            $followUpRequest->patient_id,
            NotificationType::FOLLOW_UP_REJECTED,
            'Follow-up Request Rejected',
            'Your follow-up request was rejected. Reason: ' . $validated['decision_notes'],
            [
                'follow_up_request_id' => $followUpRequest->id,
                'consultation_id' => $followUpRequest->consultation_id,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Follow-up request rejected.',
            ]);
        }

        return back()->with('status', 'Follow-up request rejected.');
    }

    public function consultationHistory(User $nurse)
    {
        $this->authorizeNurse($nurse);

        return view('nurse.consultation_history', [
            'nurse' => $nurse,
        ]);
    }

    private function getConsultationInboxData(User $nurse): array
    {
        $currentNurseId = (int) $nurse->user_id;

        $pendingRequests = Consultation::with(['patient', 'physician'])
            ->where('request_status', 'pending')
            ->orderByDesc('submitted_at')
            ->get();

        $assignedRequests = Consultation::with(['patient', 'nurse', 'physician'])
            ->whereIn('request_status', ['reviewed', 'assigned', 'active', 'scheduled'])
            ->orderByDesc('submitted_at')
            ->get();

        return [
            'pendingRequests' => $pendingRequests,
            'assignedToCurrentNurse' => $assignedRequests
                ->where('assigned_nurse_id', $currentNurseId)
                ->values(),
            'assignedToOtherNurses' => $assignedRequests
                ->filter(fn ($request) => (int) $request->assigned_nurse_id !== $currentNurseId)
                ->values(),
        ];
    }

    private function serializeConsultations($consultations): array
    {
        return $consultations->map(function ($request) {
            return [
                'request_id' => $request->request_id,
                'patient_id' => $request->patient_id,
                'patient_name' => trim(optional($request->patient)->first_name . ' ' . optional($request->patient)->last_name) ?: 'Unknown Patient',
                'patient_is_online' => $this->isUserOnline($request->patient),
                'concern_category' => $request->concern_category,
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $request->request_status,
                'assigned_nurse_id' => $request->assigned_nurse_id,
                'assigned_nurse_name' => trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: null,
                'assigned_physician_id' => $request->assigned_physician_id,
                'assigned_physician_name' => trim(optional($request->physician)->first_name . ' ' . optional($request->physician)->last_name) ?: null,
                'priority_level' => $request->priority_level,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'file_attachments' => array_map(function ($path) use ($request) {
                    return url('/consultations/' . $request->request_id . '/attachments/' . basename($path));
                }, $request->file_attachments ?? []),
            ];
        })->values()->all();
    }

    private function isUserOnline(?User $user): bool
    {
        return $user
            && $user->online_status === 'online'
            && $user->last_seen_at
            && $user->last_seen_at->gt(now()->subMinutes(2));
    }
}
