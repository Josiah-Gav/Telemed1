<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateScheduleSlotsRequest;
use App\Http\Requests\StoreScheduleSlotsRequest;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Enums\NotificationType;
use App\Services\ConsultationOwnershipService;
use App\Services\DashboardAnalyticsService;
use App\Services\Export\ConsultationHistoryQuery;
use App\Services\Export\ConsultationHistoryRows;
use App\Services\Export\DashboardExportRows;
use App\Services\NotificationService;
use App\Support\CsvDownload;
use App\Support\DateRange;
use App\Support\StatusBadge;
use Barryvdh\DomPDF\Facade\Pdf;

class PhysicianController extends Controller
{
    public function __construct(
        private readonly ConsultationOwnershipService $ownershipService,
        private readonly DashboardAnalyticsService $analyticsService,
    ) {
        $this->middleware('auth');
    }

    private function authorizePhysician(User $physician)
    {
        if (Auth::user()->role !== 'physician' || Auth::id() !== $physician->user_id) {
            abort(403, 'Unauthorized access.');
        }
    }

    public function dashboard(User $physician, Request $request)
    {
        $this->authorizePhysician($physician);

        $dateRange = DateRange::fromInput(
            $request->query('range'),
            $request->query('start'),
            $request->query('end'),
            'this_month',
        );

        return view('physician.dashboard', [
            'analytics' => $this->analyticsService->forPhysician($physician, $dateRange),
            'dateRange' => $dateRange,
        ]);
    }

    /**
     * CSV/PDF export of the same analytics the physician dashboard renders,
     * scoped and date-ranged identically — see DashboardExportRows' class
     * docblock for why the mapping step never recomputes a metric. Both
     * formats consume the same $sections, so they cannot disagree.
     */
    public function dashboardExport(User $physician, Request $request)
    {
        $this->authorizePhysician($physician);

        $format = (string) $request->query('format', 'csv');

        if (! in_array($format, ['csv', 'pdf'], true)) {
            abort(422, 'Unsupported export format.');
        }

        $dateRange = DateRange::fromInput(
            $request->query('range'),
            $request->query('start'),
            $request->query('end'),
            'this_month',
        );

        // The authenticated user's canonical display name — same
        // trim(first_name.' '.last_name) convention used everywhere else in
        // this app (see ConsultationHistoryRows::relationName()) — resolved
        // here in the controller and passed into the pure mapper; the mapper
        // never touches Auth itself.
        $generatedBy = trim(Auth::user()->first_name.' '.Auth::user()->last_name);
        $timelineLabel = DashboardExportRows::timelineLabel($dateRange->preset, $dateRange->start->toDateString(), $dateRange->end->toDateString());

        $analytics = $this->analyticsService->forPhysician($physician, $dateRange);
        $sections = DashboardExportRows::forRole('physician', $analytics, now(), $generatedBy, $timelineLabel);
        $filename = $this->sanitizeExportFilename("Physician {$generatedBy} {$timelineLabel} Report");

        if ($format === 'pdf') {
            // no-store mirrors CsvDownload: these reports carry
            // patient-derived aggregates and must not be cached.
            return Pdf::loadView('exports.dashboard', ['sections' => $sections])
                ->setPaper('a4', 'portrait')
                ->download($filename.'.pdf')
                ->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
        }

        return CsvDownload::stream(
            $filename.'.csv',
            DashboardExportRows::toCsvRows($sections),
        );
    }

    public function consultationInbox(User $physician)
    {
        $this->authorizePhysician($physician);

        $consultationInboxData = $this->getConsultationInboxData();
        $serializedInboxData = [
            'normalPriorityConsultations' => $this->serializeConsultations($consultationInboxData['normalPriorityConsultations'], $physician),
            'highPriorityConsultations' => $this->serializeConsultations($consultationInboxData['highPriorityConsultations'], $physician),
        ];

        return view('physician.consultation_inbox', [
            'physician' => $physician,
            'normalPriorityConsultations' => $consultationInboxData['normalPriorityConsultations'],
            'highPriorityConsultations' => $consultationInboxData['highPriorityConsultations'],
            'physicianInboxData' => $serializedInboxData,
        ]);
    }

    public function consultationInboxRefresh(User $physician)
    {
        $this->authorizePhysician($physician);

        $consultationInboxData = $this->getConsultationInboxData();

        return response()->json([
            'normalPriorityConsultations' => $this->serializeConsultations($consultationInboxData['normalPriorityConsultations'], $physician),
            'highPriorityConsultations' => $this->serializeConsultations($consultationInboxData['highPriorityConsultations'], $physician),
        ]);
    }

    public function startConsultation(Request $request, User $physician, Consultation $consultation)
    {
        $this->authorizePhysician($physician);

        $validated = $request->validate([
            'physician_id' => 'required|integer',
        ]);

        if ((int) $validated['physician_id'] !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        try {
            $result = $this->ownershipService->startByPhysician(
                (int) $consultation->request_id,
                (int) Auth::id()
            );

            $consultation = $result['consultation'];
            $session = $result['session'];
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        NotificationService::sendUnique(
            $consultation->patient_id,
            NotificationType::CONSULTATION_STARTED,
            'Consultation Started',
            'Your consultation has started. The physician is now available to assist you.',
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'session_id' => $session->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Consultation started successfully.',
        ]);
    }

    private function getConsultationInboxData(): array
    {
        $assignedConsultations = Consultation::with(['patient', 'nurse', 'consultationSession.slot'])
            ->whereIn('request_status', ['reviewed', 'assigned', 'scheduled'])
            ->orderByDesc('submitted_at')
            ->get();

        return [
            'normalPriorityConsultations' => $assignedConsultations
                ->where('priority_level', 'Normal')
                ->values(),
            'highPriorityConsultations' => $assignedConsultations
                ->where('priority_level', 'High')
                ->values(),
        ];
    }

    private function serializeConsultations($consultations, User $physician): array
    {
        $currentPhysicianId = $physician->user_id;

        return $consultations->map(function ($consultation) use ($currentPhysicianId) {
            $canStart = $this->resolveCanStart($consultation->request_status, $consultation->consultationSession);
            $severity = StatusBadge::highestSeverity($consultation->symptoms_desc);

            return [
                'request_id' => $consultation->request_id,
                'patient_name' => trim(optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name) ?: 'Unknown Patient',
                'patient_is_online' => $this->isUserOnline($consultation->patient),
                'assigned_nurse_name' => trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: 'Unassigned',
                'concern_category' => $consultation->concern_category,
                'submitted_at' => $consultation->submitted_at ? $consultation->submitted_at->format('M. j, Y g:i A') : null,
                'request_status' => $consultation->request_status,
                'priority_level' => $consultation->priority_level,
                'symptoms_desc' => $consultation->symptoms_desc,
                'online_reason' => $consultation->online_reason,
                'additional_information' => $consultation->additional_information,
                // Presentation tokens are resolved server-side because the inbox
                // table renders rows with Alpine x-for (for the AJAX refresh) and
                // so cannot use the <x-dash.badge> Blade component per row.
                'severity' => $severity,
                'severity_badge' => StatusBadge::severity($severity),
                'status_badge' => StatusBadge::status($consultation->request_status),
                'priority_badge' => StatusBadge::priority($consultation->priority_level),
                'reject_url' => route('physician.consultations.reject_reviewed', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'start_url' => route('physician.consultations.start', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'available_slots_url' => route('physician.consultations.available_slots', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'schedule_url' => route('physician.consultations.schedule', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'scheduled_slot' => $this->serializeScheduledSlot($consultation->consultationSession),
                'can_start' => $canStart['can_start'],
                'can_start_message' => $canStart['can_start_message'],
                'file_attachments' => $this->serializeAttachmentUrls($consultation),
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

    /**
     * Attachments are exposed as consultation.attachment URLs rather than the
     * raw stored values, because a stored value may be a local disk path that
     * is not web-reachable (see the Cloudinary fallback in CLAUDE.md). The
     * basename is normalised exactly as AttachmentController::show does, so a
     * Cloudinary URL carrying a query string still matches on the way back in.
     *
     * @return array<int, string>
     */
    private function serializeAttachmentUrls(Consultation $consultation): array
    {
        return array_values(array_map(function ($path) use ($consultation) {
            $urlPath = parse_url($path, PHP_URL_PATH) ?: $path;

            return route('consultation.attachment', [
                'consultation' => $consultation->request_id,
                'file' => basename($urlPath),
            ]);
        }, $consultation->file_attachments ?? []));
    }

    public function availableScheduleSlotsForConsultation(User $physician, Consultation $consultation): JsonResponse
    {
        $this->authorizePhysician($physician);

        if (!in_array($consultation->request_status, ['reviewed', 'assigned', 'scheduled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This consultation cannot be scheduled.',
            ], 422);
        }

        $today = now()->toDateString();

        $slots = ScheduleSlot::query()
            ->where('physician_id', $physician->user_id)
            ->whereDate('slot_date', '>=', $today)
            ->where('status', 'available')
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get()
            ->filter(function (ScheduleSlot $slot) {
                return !$this->isScheduleSlotInPast($slot);
            })
            ->map(function (ScheduleSlot $slot) {
                $start = CarbonImmutable::createFromFormat('H:i:s', $slot->start_time);
                $end = CarbonImmutable::createFromFormat('H:i:s', $slot->end_time);

                return [
                    'slot_id' => $slot->slot_id,
                    'slot_date' => $slot->slot_date?->format('Y-m-d') ?? $slot->slot_date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'label' => $start->format('g:i A') . ' - ' . $end->format('g:i A'),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'slots' => $slots,
            'no_slots' => count($slots) === 0,
            'manage_schedule_url' => route('physician.scheduled_consultation', ['physician' => $physician->user_id]),
        ]);
    }

    public function scheduleConsultation(Request $request, User $physician, Consultation $consultation): JsonResponse
    {
        $this->authorizePhysician($physician);

        $validated = $request->validate([
            'physician_id' => 'required|integer',
            'slot_id' => 'required|integer',
        ]);

        if ((int) $validated['physician_id'] !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], 403);
        }

        try {
            $result = $this->ownershipService->scheduleByPhysician(
                (int) $consultation->request_id,
                (int) Auth::id(),
                (int) $validated['slot_id']
            );

            $consultation = $result['consultation'];
            $slot = $result['slot'];
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        NotificationService::sendUnique(
            $consultation->patient_id,
            NotificationType::CONSULTATION_SCHEDULED,
            'Consultation Scheduled',
            'Your consultation is scheduled for ' . optional($slot?->slot_date)->format('M d, Y') . ' at ' . $slot?->start_time . '.',
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'schedule_slot_id' => $slot?->slot_id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Consultation scheduled successfully.',
        ]);
    }

    public function rejectReviewedConsultation(Request $request, User $physician, Consultation $consultation)
    {
        $this->authorizePhysician($physician);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $consultation = $this->ownershipService->rejectReviewedByPhysician(
                (int) $consultation->request_id,
                (int) Auth::id(),
                (string) $validated['rejection_reason']
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        NotificationService::send(
            $consultation->patient_id,
            NotificationType::CONSULTATION_REVIEWED,
            'Consultation Rejected',
            'Your consultation request was rejected by the physician. Reason: ' . $validated['rejection_reason'],
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'rejected' => true,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Consultation rejected successfully.',
        ]);
    }

    public function followUpRequests(User $physician)
    {
        $this->authorizePhysician($physician);

        $forwardedRequests = FollowUpRequest::with(['patient', 'consultation.request', 'consultation.physician'])
            ->where('status', 'forwarded')
            ->orderByDesc('reviewed_at')
            ->get();

        return view('physician.follow_up_request', [
            'physician' => $physician,
            'forwardedRequests' => $forwardedRequests,
        ]);
    }

    public function decideFollowUpRequest(Request $request, User $physician, FollowUpRequest $followUpRequest)
    {
        $this->authorizePhysician($physician);

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'mode' => 'required_if:decision,approved|in:immediate,scheduled',
            'slot_id' => 'nullable|integer',
            'decision_notes' => 'nullable|string|max:2000',
        ]);

        try {
            $followUpRequest = $this->ownershipService->decideFollowUpByPhysician(
                (int) $followUpRequest->id,
                (int) Auth::id(),
                (string) $validated['decision'],
                $validated['mode'] ?? null,
                isset($validated['slot_id']) ? (int) $validated['slot_id'] : null,
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

        if ($validated['decision'] === 'rejected') {
            NotificationService::send(
                $followUpRequest->patient_id,
                NotificationType::FOLLOW_UP_REJECTED,
                'Follow-up Request Rejected',
                'Your follow-up request was rejected. Reason: ' . ($validated['decision_notes'] ?? 'No reason provided.'),
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

        NotificationService::sendUnique(
            $followUpRequest->patient_id,
            NotificationType::FOLLOW_UP_APPROVED,
            'Follow-up Request Approved',
            'Your follow-up request has been approved and a consultation has been created.',
            [
                'follow_up_request_id' => $followUpRequest->id,
                'consultation_id' => $followUpRequest->consultation_id,
            ]
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Follow-up request approved and consultation created.',
            ]);
        }

        return back()->with('status', 'Follow-up request approved and consultation created.');
    }

    public function availableSlotsForFollowUpRequest(User $physician, FollowUpRequest $followUpRequest): JsonResponse
    {
        $this->authorizePhysician($physician);

        if ($followUpRequest->status !== 'forwarded') {
            return response()->json([
                'success' => false,
                'message' => 'Only forwarded follow-up requests can be scheduled.',
            ], 422);
        }

        $today = now()->toDateString();

        $slots = ScheduleSlot::query()
            ->where('physician_id', $physician->user_id)
            ->whereDate('slot_date', '>=', $today)
            ->where('status', 'available')
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get()
            ->filter(function (ScheduleSlot $slot) {
                return !$this->isScheduleSlotInPast($slot);
            })
            ->map(function (ScheduleSlot $slot) {
                $start = CarbonImmutable::createFromFormat('H:i:s', $slot->start_time);
                $end = CarbonImmutable::createFromFormat('H:i:s', $slot->end_time);

                return [
                    'slot_id' => $slot->slot_id,
                    'slot_date' => $slot->slot_date?->format('Y-m-d') ?? $slot->slot_date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'label' => $start->format('g:i A') . ' - ' . $end->format('g:i A'),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'slots' => $slots,
            'no_slots' => count($slots) === 0,
            'manage_schedule_url' => route('physician.scheduled_consultation', ['physician' => $physician->user_id]),
        ]);
    }

    public function availableSlotsForPhysicianFollowUp(User $physician, ConsultationSession $session): JsonResponse
    {
        $this->authorizePhysician($physician);

        if ((int) $session->physician_id !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the assigned physician can schedule a follow-up for this consultation.',
            ], 403);
        }

        $consultation = Consultation::query()
            ->where('request_id', $session->request_id)
            ->first();

        if (!$consultation || $consultation->request_status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Follow-up can only be scheduled from a completed consultation.',
            ], 422);
        }

        $today = now()->toDateString();

        $slots = ScheduleSlot::query()
            ->where('physician_id', $physician->user_id)
            ->whereDate('slot_date', '>=', $today)
            ->where('status', 'available')
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get()
            ->filter(function (ScheduleSlot $slot) {
                return !$this->isScheduleSlotInPast($slot);
            })
            ->map(function (ScheduleSlot $slot) {
                $start = CarbonImmutable::createFromFormat('H:i:s', $slot->start_time);
                $end = CarbonImmutable::createFromFormat('H:i:s', $slot->end_time);

                return [
                    'slot_id' => $slot->slot_id,
                    'slot_date' => $slot->slot_date?->format('Y-m-d') ?? $slot->slot_date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'label' => $start->format('g:i A') . ' - ' . $end->format('g:i A'),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'slots' => $slots,
            'no_slots' => count($slots) === 0,
            'manage_schedule_url' => route('physician.scheduled_consultation', ['physician' => $physician->user_id]),
        ]);
    }

    public function createPhysicianFollowUp(Request $request, User $physician, ConsultationSession $session): JsonResponse
    {
        $this->authorizePhysician($physician);

        if ((int) $session->physician_id !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Only the assigned physician can create a follow-up for this consultation.',
            ], 403);
        }

        if ($session->consultation_status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Follow-up can only be created from a completed consultation.',
            ], 422);
        }

        $validated = $request->validate([
            'mode' => 'required|in:immediate,scheduled',
            'slot_id' => 'nullable|integer',
            'decision_notes' => 'required|string|max:2000',
        ]);

        try {
            DB::transaction(function () use ($session, $validated) {
                $followUpConsultation = $this->createFollowUpConsultationFromSource(
                    $session,
                    Auth::id(),
                    $validated['mode'],
                    $validated['slot_id'] ?? null
                );

                FollowUpRequest::create([
                    'consultation_id' => $session->id,
                    'patient_id' => $session->request?->patient_id,
                    'reason' => 'Physician scheduled a follow-up consultation directly.',
                    'status' => 'approved',
                    'decision_notes' => $validated['decision_notes'],
                    'reviewed_by_nurse_id' => null,
                    'reviewed_at' => now(),
                    'decided_by_physician_id' => Auth::id(),
                    'decided_at' => now(),
                ]);

                $followUpConsultation->update([
                    'request_status' => $validated['mode'] === 'immediate' ? 'active' : 'scheduled',
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        NotificationService::send(
            $session->request?->patient_id,
            NotificationType::PHYSICIAN_REQUEST,
            'Physician-Initiated Follow-up',
            'Your physician has scheduled a follow-up consultation for you.',
            [
                'consultation_id' => $session->request_id,
                'request_id' => $session->request_id,
                'session_id' => $session->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Follow-up consultation created successfully.',
        ]);
    }

    public function consultationHistory(User $physician)
    {
        $this->authorizePhysician($physician);

        // Filtering and query construction live in ConsultationHistoryQuery so
        // the Phase 6 export can reuse the exact same semantics. Authorization
        // stays here (authorizePhysician above); the service never touches Auth.
        $search = trim((string) request()->query('search', ''));

        $filters = ConsultationHistoryQuery::normalizeFilters(
            request()->query('date_filter', 'all'),
            request()->query('status', 'all'),
            request()->query('consultation_type', 'all'),
        ) + ['search' => $search];

        $historyConsultations = ConsultationHistoryQuery::forPhysician((int) Auth::id(), $filters)->get();

        // Moved into ConsultationHistoryQuery::decorateHasExistingFollowUp()
        // in Phase 6 so the physician history export can reproduce the exact
        // same value from one implementation — see that method's docblock.
        ConsultationHistoryQuery::decorateHasExistingFollowUp($historyConsultations);

        // $filters already holds exactly the previous
        // date_filter/status/consultation_type/search array, in that order,
        // built by normalizeFilters() above.
        if (request()->ajax()) {
            return response()->json([
                'html' => view('physician.partials.consultation_history_table', [
                    'historyConsultations' => $historyConsultations,
                    'physician' => $physician,
                ])->render(),
            ]);
        }

        return view('physician.consultation_history', [
            'physician' => $physician,
            'historyConsultations' => $historyConsultations,
            'filters' => $filters,
        ]);
    }

    /**
     * CSV/PDF export of this physician's own consultation history. Filtering,
     * scoping, ordering, and the has_existing_follow_up decoration are
     * identical to consultationHistory() above — both call
     * ConsultationHistoryQuery and ConsultationHistoryQuery::
     * decorateHasExistingFollowUp(), so the export can never disagree with
     * what the HTML page currently shows for the same query string.
     */
    public function consultationHistoryExport(User $physician, Request $request)
    {
        $this->authorizePhysician($physician);

        $format = (string) $request->query('format', 'csv');

        if (! in_array($format, ['csv', 'pdf'], true)) {
            abort(422, 'Unsupported export format.');
        }

        $search = trim((string) $request->query('search', ''));

        $filters = ConsultationHistoryQuery::normalizeFilters(
            $request->query('date_filter', 'all'),
            $request->query('status', 'all'),
            $request->query('consultation_type', 'all'),
        ) + ['search' => $search];

        $historyConsultations = ConsultationHistoryQuery::forPhysician((int) Auth::id(), $filters)->get();

        ConsultationHistoryQuery::decorateHasExistingFollowUp($historyConsultations);

        $rows = ConsultationHistoryRows::physicianRows($historyConsultations);

        // The authenticated user's canonical display name — resolved from
        // Auth explicitly here (not from the route-bound $physician, even
        // though authorizePhysician() above already guarantees they're the
        // same user) so every export action obtains it the same way.
        $generatedBy = trim(Auth::user()->first_name.' '.Auth::user()->last_name);
        $timelineLabel = ConsultationHistoryRows::timelineLabel($filters['date_filter'] ?? 'all');

        $title = "Physician {$generatedBy} {$timelineLabel} History Report";
        $meta = array_merge([
            ['Role', 'Physician'],
            ['Owner', trim($physician->first_name.' '.$physician->last_name)],
            ['Generated By', $generatedBy],
        ], ConsultationHistoryRows::filterSummaryRows($filters), [
            ['Generated', now()->format('Y-m-d H:i')],
        ]);

        $filename = $this->sanitizeExportFilename($title);

        if ($format === 'pdf') {
            $totalCount = count($rows);
            $pdfRows = array_slice($rows, 0, ConsultationHistoryRows::PDF_ROW_CAP);

            return Pdf::loadView('exports.consultation-history', [
                'title' => $title,
                'meta' => $meta,
                'headers' => ConsultationHistoryRows::PHYSICIAN_HEADERS,
                'rows' => $pdfRows,
                'totalCount' => $totalCount,
                'truncated' => $totalCount > ConsultationHistoryRows::PDF_ROW_CAP,
                'rowCap' => ConsultationHistoryRows::PDF_ROW_CAP,
            ])
                ->setPaper('a4', 'landscape')
                ->download($filename.'.pdf')
                ->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                    'Expires' => '0',
                ]);
        }

        return CsvDownload::stream(
            $filename.'.csv',
            ConsultationHistoryRows::toCsvRows($title, $meta, ConsultationHistoryRows::PHYSICIAN_HEADERS, $rows),
        );
    }

    public function activeConsultations(User $physician)
    {
        $this->authorizePhysician($physician);

        $activeConsultations = Consultation::with(['patient', 'nurse'])
            ->where('request_status', 'active')
            ->where('assigned_physician_id', Auth::id())
            ->orderByDesc('submitted_at')
            ->get();

        // Backfill missing consultation sessions for active records created before messaging rollout.
        $activeConsultations->each(function (Consultation $consultation) {
            if ($consultation->request_status !== 'active') {
                return;
            }

            $session = ConsultationSession::firstOrCreate(
                ['request_id' => $consultation->request_id],
                [
                    'physician_id' => Auth::id(),
                    'consultation_status' => 'active',
                    'assessment' => 'Initial assessment pending.',
                    'plan' => 'Plan to be documented during consultation.',
                    'recommendations' => 'Recommendations to follow after evaluation.',
                    'assigned_at' => now(),
                    'started_at' => now(),
                ]
            );

            if ((int) $session->physician_id !== (int) Auth::id()) {
                $session->update([
                    'physician_id' => Auth::id(),
                ]);
            }

            if ($session->consultation_status !== 'active') {
                $session->update([
                    'consultation_status' => 'active',
                ]);
            }
        });

        $activeConsultations->load('consultationSession');

        return view('physician.active_consultation', [
            'physician' => $physician,
            'activeConsultations' => $activeConsultations,
        ]);
    }

    public function scheduledConsultations(User $physician)
    {
        $this->authorizePhysician($physician);

        $currentPhysicianId = $physician->user_id;
        $this->syncMissedSlotsForPhysician($currentPhysicianId);
        $existingSlots = $this->getUpcomingSlotsForPhysician($currentPhysicianId);
        $scheduledConsultations = $this->getScheduledConsultationsForPhysician($physician);

        return view('physician.scheduled_consultation', [
            'physician' => $physician,
            'existingSlots' => $existingSlots,
            'scheduledConsultations' => $scheduledConsultations,
            'slotRoutes' => [
                'refresh_url' => route('physician.scheduled_consultation.slots', ['physician' => $currentPhysicianId]),
                'generate_url' => route('physician.scheduled_consultation.generate', ['physician' => $currentPhysicianId]),
                'save_url' => route('physician.scheduled_consultation.save', ['physician' => $currentPhysicianId]),
            ],
        ]);
    }

    public function scheduledConsultationSlots(User $physician): JsonResponse
    {
        $this->authorizePhysician($physician);

        $this->syncMissedSlotsForPhysician($physician->user_id);

        return response()->json([
            'slots' => $this->getUpcomingSlotsForPhysician($physician->user_id),
        ]);
    }

    private function getScheduledConsultationsForPhysician(User $physician): array
    {
        $physicianId = $physician->user_id;

        $consultations = Consultation::with(['patient', 'consultationSession.slot'])
            ->where('request_status', 'scheduled')
            ->where('assigned_physician_id', $physicianId)
            ->orderByDesc('submitted_at')
            ->get();

        return $consultations->map(function (Consultation $consultation) use ($physicianId) {
            $session = $consultation->consultationSession;
            $slot = $session?->slot;

            $scheduledDate = null;
            $scheduledTimeLabel = null;
            $scheduledAtIso = null;

            if ($slot) {
                $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
                $start = CarbonImmutable::createFromFormat('H:i:s', $slot->start_time);
                $end = CarbonImmutable::createFromFormat('H:i:s', $slot->end_time);
                $scheduledDate = $slotDate;
                $scheduledTimeLabel = $start->format('g:i A') . ' - ' . $end->format('g:i A');
                $scheduledAtIso = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time)->toIso8601String();
            }

            return [
                'request_id' => $consultation->request_id,
                'patient_name' => trim(optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name) ?: 'Unknown Patient',
                'patient_is_online' => $this->isUserOnline($consultation->patient),
                'concern_category' => $consultation->concern_category,
                'priority_level' => $consultation->priority_level,
                'consultation_type' => $consultation->type === 'follow_up' ? 'follow_up' : 'initial',
                'scheduled_date' => $scheduledDate,
                'scheduled_time_label' => $scheduledTimeLabel,
                'scheduled_at_iso' => $scheduledAtIso,
                'slot_status' => $slot?->status,
                'start_url' => route('physician.consultations.start', ['physician' => $physicianId, 'consultation' => $consultation]),
                'available_slots_url' => route('physician.consultations.available_slots', ['physician' => $physicianId, 'consultation' => $consultation]),
                'schedule_url' => route('physician.consultations.schedule', ['physician' => $physicianId, 'consultation' => $consultation]),
                'messaging_url' => $session
                    ? route('consultations.messaging.show', $session)
                    : null,
            ];
        })->values()->all();
    }

    public function generateScheduleSlots(GenerateScheduleSlotsRequest $request, User $physician): JsonResponse
    {
        $this->authorizePhysician($physician);

        $validated = $request->validated();

        $slotDate = CarbonImmutable::createFromFormat('Y-m-d', $validated['slot_date'])->startOfDay();
        $dayStart = $this->combineDateAndTime($slotDate, $validated['start_time']);
        $dayEnd = $this->combineDateAndTime($slotDate, $validated['end_time']);

        $breakRange = null;
        if (!empty($validated['break_start_time']) && !empty($validated['break_end_time'])) {
            $breakStart = $this->combineDateAndTime($slotDate, $validated['break_start_time']);
            $breakEnd = $this->combineDateAndTime($slotDate, $validated['break_end_time']);

            if ($breakStart->lessThan($dayStart) || $breakEnd->greaterThan($dayEnd)) {
                return response()->json([
                    'message' => 'Break range must be inside working hours.',
                ], 422);
            }

            $breakRange = ['start' => $breakStart, 'end' => $breakEnd];
        }

        $durationMinutes = (int) $validated['duration_minutes'];
        $existingSlots = ScheduleSlot::query()
            ->where('physician_id', $physician->user_id)
            ->whereDate('slot_date', $slotDate->toDateString())
            ->get(['start_time', 'end_time']);

        $generatedSlots = [];
        $skippedByBreak = 0;
        $skippedByConflict = 0;

        for ($cursor = $dayStart; $cursor->lessThan($dayEnd); $cursor = $cursor->addMinutes($durationMinutes)) {
            $slotStart = $cursor;
            $slotEnd = $cursor->addMinutes($durationMinutes);

            if ($slotEnd->greaterThan($dayEnd)) {
                break;
            }

            if ($this->overlapsRange($slotStart, $slotEnd, $breakRange)) {
                $skippedByBreak++;
                continue;
            }

            if ($this->overlapsExistingSlots($slotStart, $slotEnd, $existingSlots)) {
                $skippedByConflict++;
                continue;
            }

            $generatedSlots[] = [
                'slot_date' => $slotDate->toDateString(),
                'start_time' => $slotStart->format('H:i:s'),
                'end_time' => $slotEnd->format('H:i:s'),
                'label' => $slotStart->format('g:i A') . ' - ' . $slotEnd->format('g:i A'),
                'selected' => true,
            ];
        }

        return response()->json([
            'slots' => $generatedSlots,
            'summary' => [
                'generated_count' => count($generatedSlots),
                'skipped_by_break' => $skippedByBreak,
                'skipped_by_conflict' => $skippedByConflict,
            ],
        ]);
    }

    public function saveScheduleSlots(StoreScheduleSlotsRequest $request, User $physician): JsonResponse
    {
        $this->authorizePhysician($physician);

        $validated = $request->validated();
        $slotDate = CarbonImmutable::createFromFormat('Y-m-d', $validated['slot_date'])->toDateString();
        $incomingSlots = collect($validated['slots']);

        foreach ($incomingSlots as $slot) {
            if (($slot['start_time'] ?? '') >= ($slot['end_time'] ?? '')) {
                return response()->json([
                    'message' => 'Each slot end time must be later than start time.',
                ], 422);
            }
        }

        $existingSlots = ScheduleSlot::query()
            ->where('physician_id', $physician->user_id)
            ->whereDate('slot_date', $slotDate)
            ->get(['start_time', 'end_time']);

        $toInsert = [];
        $skippedByConflict = 0;

        foreach ($incomingSlots as $slot) {
            $slotStart = $this->combineDateAndTime(CarbonImmutable::parse($slotDate), $slot['start_time']);
            $slotEnd = $this->combineDateAndTime(CarbonImmutable::parse($slotDate), $slot['end_time']);

            if ($this->overlapsExistingSlots($slotStart, $slotEnd, $existingSlots)) {
                $skippedByConflict++;
                continue;
            }

            $toInsert[] = [
                'physician_id' => $physician->user_id,
                'slot_date' => $slotDate,
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Track accepted slot in memory to prevent overlap inside the same payload.
            $existingSlots->push((object) [
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
            ]);
        }

        if (!empty($toInsert)) {
            ScheduleSlot::insert($toInsert);
        }

        return response()->json([
            'success' => true,
            'message' => 'Schedule slots saved.',
            'summary' => [
                'saved_count' => count($toInsert),
                'skipped_by_conflict' => $skippedByConflict,
            ],
            'slots' => $this->getUpcomingSlotsForPhysician($physician->user_id),
        ]);
    }

    private function getUpcomingSlotsForPhysician(int $physicianId): array
    {
        $today = now()->toDateString();

        $slots = ScheduleSlot::query()
            ->where('physician_id', $physicianId)
            ->where(function ($query) use ($today) {
                $query
                    ->whereDate('slot_date', '>=', $today)
                    ->orWhereIn('status', ['missed', 'completed']);
            })
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get();

        $completedSessionBySlotId = ConsultationSession::query()
            ->where('physician_id', $physicianId)
            ->where('consultation_status', 'completed')
            ->whereNotNull('slot_id')
            ->whereIn('slot_id', $slots->pluck('slot_id')->all())
            ->whereHas('request', function ($query) use ($physicianId) {
                $query
                    ->where('request_status', 'completed')
                    ->where('assigned_physician_id', $physicianId);
            })
            ->get()
            ->keyBy('slot_id');

        return $slots
            ->map(function (ScheduleSlot $slot) use ($completedSessionBySlotId) {
                $startTime = CarbonImmutable::createFromFormat('H:i:s', $slot->start_time);
                $endTime = CarbonImmutable::createFromFormat('H:i:s', $slot->end_time);
                $completedSession = $completedSessionBySlotId->get($slot->slot_id);

                return [
                    'slot_id' => $slot->slot_id,
                    'slot_date' => $slot->slot_date?->format('Y-m-d') ?? $slot->slot_date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'status' => $slot->status,
                    'label' => $startTime->format('g:i A') . ' - ' . $endTime->format('g:i A'),
                    'messaging_url' => ($slot->status === 'completed' && $completedSession)
                        ? route('consultations.messaging.show', $completedSession)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    private function syncMissedSlotsForPhysician(int $physicianId): void
    {
        // Reconcile booked slots that already belong to completed consultations.
        ConsultationSession::query()
            ->where('consultation_status', 'completed')
            ->whereNotNull('slot_id')
            ->whereHas('request', function ($query) use ($physicianId) {
                $query
                    ->where('request_status', 'completed')
                    ->where('assigned_physician_id', $physicianId);
            })
            ->with(['slot' => function ($query) use ($physicianId) {
                $query
                    ->where('physician_id', $physicianId)
                    ->where('status', 'booked');
            }])
            ->get()
            ->each(function (ConsultationSession $session) {
                if ($session->slot) {
                    $session->slot->update([
                        'status' => 'completed',
                    ]);
                }
            });

        $now = CarbonImmutable::now();

        $scheduledSessions = ConsultationSession::query()
            ->where('consultation_status', 'scheduled')
            ->whereNotNull('slot_id')
            ->whereHas('request', function ($query) use ($physicianId) {
                $query
                    ->where('request_status', 'scheduled')
                    ->where('assigned_physician_id', $physicianId);
            })
            ->with(['slot' => function ($query) use ($physicianId) {
                $query
                    ->where('physician_id', $physicianId)
                    ->where('status', 'booked');
            }])
            ->get();

        foreach ($scheduledSessions as $session) {
            $slot = $session->slot;

            if (!$slot || $slot->status !== 'booked') {
                continue;
            }

            $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
            $slotEndsAt = CarbonImmutable::parse($slotDate . ' ' . $slot->end_time);

            if ($now->lessThanOrEqualTo($slotEndsAt)) {
                continue;
            }

            $slot->update([
                'status' => 'missed',
            ]);

            $patientId = $session->request?->patient_id;
            if ($patientId) {
                NotificationService::sendUnique(
                    $patientId,
                    NotificationType::CONSULTATION_MISSED,
                    'Consultation Missed',
                    'Your scheduled consultation was missed. Please contact the infirmary to reschedule.',
                    [
                        'consultation_id' => $session->request_id,
                        'request_id' => $session->request_id,
                        'schedule_slot_id' => $slot->slot_id,
                    ]
                );
            }

            NotificationService::sendUnique(
                $physicianId,
                NotificationType::CONSULTATION_MISSED,
                'Consultation Missed',
                'A scheduled consultation slot was missed. Please reschedule the consultation.',
                [
                    'consultation_id' => $session->request_id,
                    'request_id' => $session->request_id,
                    'schedule_slot_id' => $slot->slot_id,
                ]
            );
        }
    }

    private function combineDateAndTime(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setHour($hour)->setMinute($minute)->setSecond(0);
    }

    private function overlapsRange(CarbonImmutable $slotStart, CarbonImmutable $slotEnd, ?array $range): bool
    {
        if ($range === null) {
            return false;
        }

        return $slotStart->lessThan($range['end']) && $slotEnd->greaterThan($range['start']);
    }

    private function overlapsExistingSlots(CarbonImmutable $slotStart, CarbonImmutable $slotEnd, Collection $existingSlots): bool
    {
        foreach ($existingSlots as $existingSlot) {
            $existingStart = CarbonImmutable::createFromFormat('H:i:s', (string) $existingSlot->start_time)
                ->setDate($slotStart->year, $slotStart->month, $slotStart->day);
            $existingEnd = CarbonImmutable::createFromFormat('H:i:s', (string) $existingSlot->end_time)
                ->setDate($slotStart->year, $slotStart->month, $slotStart->day);

            if ($slotStart->lessThan($existingEnd) && $slotEnd->greaterThan($existingStart)) {
                return true;
            }
        }

        return false;
    }

    private function serializeScheduledSlot(?ConsultationSession $session): ?array
    {
        $slot = $session?->slot;
        if (!$slot) {
            return null;
        }

        $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
        $slotDateTime = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time);
        $start = CarbonImmutable::createFromFormat('H:i:s', $slot->start_time);
        $end = CarbonImmutable::createFromFormat('H:i:s', $slot->end_time);

        return [
            'slot_id' => $slot->slot_id,
            // Display-only, matching the inbox's "Submitted At" format. The
            // machine-readable form is starts_at_iso; $slotDate stays Y-m-d
            // above because the ISO string is parsed from it.
            'slot_date' => $slot->slot_date?->format('M. j, Y') ?? $slotDate,
            'start_time' => $slot->start_time,
            'end_time' => $slot->end_time,
            'label' => $start->format('g:i A') . ' - ' . $end->format('g:i A'),
            'starts_at_iso' => $slotDateTime->toIso8601String(),
        ];
    }

    private function resolveCanStart(string $requestStatus, ?ConsultationSession $session): array
    {
        if (!in_array($requestStatus, ['reviewed', 'assigned', 'scheduled'], true)) {
            return [
                'can_start' => false,
                'can_start_message' => 'Only reviewed, assigned, or scheduled consultations can be started.',
            ];
        }

        if (in_array($requestStatus, ['reviewed', 'assigned'], true)) {
            return [
                'can_start' => true,
                'can_start_message' => 'This consultation can be started immediately.',
            ];
        }

        $slot = $session?->slot;
        if (!$slot || $slot->status !== 'booked') {
            $message = 'Assigned slot is missing or not booked.';

            if ($slot && $slot->status === 'missed') {
                $message = 'Assigned slot has been missed. Reschedule this consultation to a new available slot.';
            }

            if ($slot && $slot->status === 'completed') {
                $message = 'Assigned slot is already completed and cannot be reused.';
            }

            return [
                'can_start' => false,
                'can_start_message' => $message,
            ];
        }

        return $this->buildCanStartInfoFromSlot($slot);
    }

    private function buildCanStartInfoFromSlot(ScheduleSlot $slot): array
    {
        $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
        $slotStart = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time);
        $slotEnd = CarbonImmutable::parse($slotDate . ' ' . $slot->end_time);
        $canStartAt = $slotStart->subMinutes(15);
        $now = CarbonImmutable::now();

        if ($now->greaterThan($slotEnd)) {
            return [
                'can_start' => false,
                'can_start_message' => 'This schedule slot window has already ended. Reschedule this consultation to an available slot.',
            ];
        }

        if ($now->greaterThanOrEqualTo($canStartAt)) {
            return [
                'can_start' => true,
                'can_start_message' => 'Consultation is ready to start.',
            ];
        }

        return [
            'can_start' => false,
            'can_start_message' => 'Start will be available at ' . $canStartAt->format('M d, Y h:i A') . '.',
        ];
    }

    private function createFollowUpConsultationFromSource(ConsultationSession $sourceSession, int $physicianId, string $mode, ?int $slotId = null, ?int $followUpRequestId = null): Consultation
    {
        $lockedSourceSession = ConsultationSession::query()
            ->whereKey($sourceSession->id)
            ->lockForUpdate()
            ->first();

        if (!$lockedSourceSession) {
            throw new \RuntimeException('Source consultation was not found.');
        }

        $sourceSession = $lockedSourceSession;
        $sourceSession->loadMissing('request');

        if (!$sourceSession->request) {
            throw new \RuntimeException('Source consultation request was not found.');
        }

        $lockedSourceRequest = Consultation::query()
            ->where('request_id', $sourceSession->request->request_id)
            ->lockForUpdate()
            ->first();

        if (!$lockedSourceRequest) {
            throw new \RuntimeException('Source consultation request was not found.');
        }

        $sourceSession->setRelation('request', $lockedSourceRequest);

        $existingActiveFollowUp = Consultation::query()
            ->where('type', 'follow_up')
            ->where('parent_consultation_id', $sourceSession->id)
            ->whereIn('request_status', ['pending', 'scheduled', 'active'])
            ->lockForUpdate()
            ->exists();

        if ($existingActiveFollowUp) {
            throw new \RuntimeException('An active follow-up consultation already exists for this consultation.');
        }

        $newRequestStatus = $mode === 'immediate' ? 'active' : 'scheduled';

        $newConsultation = Consultation::create([
            'patient_id' => $sourceSession->request->patient_id,
            'assigned_physician_id' => $physicianId,
            'assigned_nurse_id' => $sourceSession->request->assigned_nurse_id,
            'type' => 'follow_up',
            'parent_consultation_id' => $sourceSession->id,
            'concern_category' => $sourceSession->request->concern_category,
            'symptoms_desc' => $sourceSession->request->symptoms_desc,
            'online_reason' => $sourceSession->request->online_reason,
            'request_status' => $newRequestStatus,
            'priority_level' => $sourceSession->request->priority_level,
            'file_attachments' => $sourceSession->request->file_attachments,
        ]);

        $newSessionData = [
            'request_id' => $newConsultation->request_id,
            'physician_id' => $physicianId,
            'follow_up_request_id' => $followUpRequestId,
            'consultation_status' => $mode === 'immediate' ? 'active' : 'scheduled',
            'assessment' => 'Initial assessment pending.',
            'plan' => 'Plan to be documented during consultation.',
            'recommendations' => 'Recommendations to follow after evaluation.',
            'assigned_at' => now(),
            'started_at' => $mode === 'immediate' ? now() : null,
        ];

        if ($mode === 'scheduled') {
            if (!$slotId) {
                throw new \RuntimeException('A schedule slot is required when approving a scheduled follow-up.');
            }

            $slot = ScheduleSlot::query()
                ->where('slot_id', $slotId)
                ->where('physician_id', $physicianId)
                ->lockForUpdate()
                ->first();

            if (!$slot || $slot->status !== 'available') {
                throw new \RuntimeException('Selected slot is no longer available.');
            }

            if ($this->isScheduleSlotInPast($slot)) {
                throw new \RuntimeException('Selected slot is already in the past. Please choose a future slot.');
            }

            $slot->update(['status' => 'booked']);
            $newSessionData['slot_id'] = $slot->slot_id;
        }

        ConsultationSession::create($newSessionData);

        return $newConsultation;
    }

    private function isScheduleSlotInPast(ScheduleSlot $slot): bool
    {
        $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
        $slotStart = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time);

        return CarbonImmutable::now()->greaterThanOrEqualTo($slotStart);
    }

    /**
     * Strips characters a filesystem/browser download would reject
     * (/ : \ * ? " < > |) from an otherwise-readable export report name —
     * e.g. "Physician Juan Dela Cruz Last 30 Days Report" stays exactly
     * as-is, spaces included, since only these nine characters are actually
     * unsafe in a filename.
     */
    private function sanitizeExportFilename(string $name): string
    {
        return str_replace(['/', ':', '\\', '*', '?', '"', '<', '>', '|'], '-', $name);
    }
}
