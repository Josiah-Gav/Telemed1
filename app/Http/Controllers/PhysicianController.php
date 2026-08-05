<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateScheduleSlotsRequest;
use App\Http\Requests\StoreScheduleSlotsRequest;
use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class PhysicianController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    private function authorizePhysician(User $physician)
    {
        if (Auth::user()->role !== 'physician' || Auth::id() !== $physician->user_id) {
            abort(403, 'Unauthorized access.');
        }
    }

    public function dashboard(User $physician)
    {
        $this->authorizePhysician($physician);

        return view('physician.dashboard');
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

        if (!in_array($consultation->request_status, ['reviewed', 'assigned', 'scheduled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only reviewed, assigned, or scheduled consultations can be started.',
            ], 422);
        }

        if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'This consultation is already being handled by another physician.',
            ], 422);
        }

        $shouldApplyScheduledGate = $consultation->request_status === 'scheduled';

        $session = ConsultationSession::firstOrCreate(
            ['request_id' => $consultation->request_id],
            [
                'physician_id' => Auth::id(),
                'consultation_status' => $shouldApplyScheduledGate ? 'scheduled' : 'active',
                'assessment' => 'Initial assessment pending.',
                'plan' => 'Plan to be documented during consultation.',
                'recommendations' => 'Recommendations to follow after evaluation.',
                'assigned_at' => now(),
                'started_at' => $shouldApplyScheduledGate ? null : now(),
            ]
        );

        if ($shouldApplyScheduledGate) {
            if (!$session->slot_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This consultation has no assigned schedule slot yet.',
                ], 422);
            }

            $slot = ScheduleSlot::query()
                ->where('slot_id', $session->slot_id)
                ->where('physician_id', Auth::id())
                ->first();

            if (!$slot || $slot->status !== 'booked') {
                $slotStatusMessage = 'The assigned schedule slot is not ready to start.';

                if ($slot && $slot->status === 'missed') {
                    $slotStatusMessage = 'The assigned schedule slot has been marked as missed. Please reschedule this consultation to an available slot.';
                }

                if ($slot && $slot->status === 'completed') {
                    $slotStatusMessage = 'The assigned schedule slot is already completed and can no longer be used to start this consultation.';
                }

                return response()->json([
                    'success' => false,
                    'message' => $slotStatusMessage,
                ], 422);
            }

            $canStartInfo = $this->buildCanStartInfoFromSlot($slot);
            if (!$canStartInfo['can_start']) {
                return response()->json([
                    'success' => false,
                    'message' => $canStartInfo['can_start_message'] ?? 'This consultation cannot be started yet.',
                ], 422);
            }
        }

        DB::transaction(function () use ($consultation, $session) {
            $consultation->update([
                'request_status' => 'active',
                'assigned_physician_id' => Auth::id(),
            ]);

            $session->update([
                'physician_id' => Auth::id(),
                'consultation_status' => 'active',
                'started_at' => now(),
            ]);
        });

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
            return [
                'request_id' => $consultation->request_id,
                'patient_name' => trim(optional($consultation->patient)->first_name . ' ' . optional($consultation->patient)->last_name) ?: 'Unknown Patient',
                'assigned_nurse_name' => trim(optional($consultation->nurse)->first_name . ' ' . optional($consultation->nurse)->last_name) ?: 'Unassigned',
                'concern_category' => $consultation->concern_category,
                'submitted_at' => $consultation->submitted_at ? $consultation->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $consultation->request_status,
                'priority_level' => $consultation->priority_level,
                'symptoms_desc' => $consultation->symptoms_desc,
                'online_reason' => $consultation->online_reason,
                'reject_url' => route('physician.consultations.reject_reviewed', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'start_url' => route('physician.consultations.start', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'available_slots_url' => route('physician.consultations.available_slots', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'schedule_url' => route('physician.consultations.schedule', ['physician' => $currentPhysicianId, 'consultation' => $consultation]),
                'scheduled_slot' => $this->serializeScheduledSlot($consultation->consultationSession),
                'can_start' => $this->resolveCanStart($consultation->request_status, $consultation->consultationSession)['can_start'],
                'can_start_message' => $this->resolveCanStart($consultation->request_status, $consultation->consultationSession)['can_start_message'],
                'file_attachments' => array_values($consultation->file_attachments ?? []),
            ];
        })->values()->all();
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

        if (!in_array($consultation->request_status, ['reviewed', 'assigned', 'scheduled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only reviewed, assigned, or scheduled consultations can be scheduled.',
            ], 422);
        }

        if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'This consultation is already being handled by another physician.',
            ], 422);
        }

        $selectedSlotId = (int) $validated['slot_id'];

        try {
            DB::transaction(function () use ($consultation, $selectedSlotId) {
                $slot = ScheduleSlot::query()
                    ->where('slot_id', $selectedSlotId)
                    ->where('physician_id', Auth::id())
                    ->lockForUpdate()
                    ->first();

                if (!$slot || $slot->status !== 'available') {
                    throw new \RuntimeException('Selected slot is no longer available.');
                }

                if ($this->isScheduleSlotInPast($slot)) {
                    throw new \RuntimeException('Selected slot is already in the past. Please choose a future slot.');
                }

                $session = ConsultationSession::query()->lockForUpdate()->firstOrCreate(
                    ['request_id' => $consultation->request_id],
                    [
                        'physician_id' => Auth::id(),
                        'consultation_status' => 'scheduled',
                        'assessment' => 'Initial assessment pending.',
                        'plan' => 'Plan to be documented during consultation.',
                        'recommendations' => 'Recommendations to follow after evaluation.',
                        'assigned_at' => now(),
                    ]
                );

                $previousSlotId = (int) ($session->slot_id ?? 0);

                if ($previousSlotId > 0 && $previousSlotId !== (int) $slot->slot_id) {
                    $previousSlot = ScheduleSlot::query()
                        ->where('slot_id', $previousSlotId)
                        ->where('physician_id', Auth::id())
                        ->lockForUpdate()
                        ->first();

                    if ($previousSlot && $previousSlot->status === 'booked') {
                        $previousSlot->update(['status' => 'available']);
                    }
                }

                $slot->update(['status' => 'booked']);

                $consultation->update([
                    'request_status' => 'scheduled',
                    'assigned_physician_id' => Auth::id(),
                ]);

                $session->update([
                    'physician_id' => Auth::id(),
                    'consultation_status' => 'scheduled',
                    'slot_id' => $slot->slot_id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

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

        if ($consultation->request_status !== 'reviewed') {
            return response()->json([
                'success' => false,
                'message' => 'Only reviewed consultations can be rejected.',
            ], 422);
        }

        $consultation->update([
            'request_status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'assigned_physician_id' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Consultation rejected successfully.',
        ]);
    }

    public function followUpRequests(User $physician)
    {
        $this->authorizePhysician($physician);

        return view('physician.follow_up_request');
    }

    public function consultationHistory(User $physician)
    {
        $this->authorizePhysician($physician);

        $completedConsultations = Consultation::with(['patient', 'nurse', 'consultationSession'])
            ->where('request_status', 'completed')
            ->where('assigned_physician_id', Auth::id())
            ->orderByDesc('updated_at')
            ->get();

        return view('physician.consultation_history', [
            'physician' => $physician,
            'completedConsultations' => $completedConsultations,
        ]);
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

        return view('physician.scheduled_consultation', [
            'physician' => $physician,
            'existingSlots' => $existingSlots,
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
            'slot_date' => $slotDate,
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

    private function isScheduleSlotInPast(ScheduleSlot $slot): bool
    {
        $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
        $slotStart = CarbonImmutable::parse($slotDate . ' ' . $slot->start_time);

        return CarbonImmutable::now()->greaterThanOrEqualTo($slotStart);
    }
}
