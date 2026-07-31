<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\User;
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

        return view('physician.consultation_inbox', [
            'physician' => $physician,
            'normalPriorityConsultations' => $consultationInboxData['normalPriorityConsultations'],
            'highPriorityConsultations' => $consultationInboxData['highPriorityConsultations'],
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

        if (!in_array($consultation->request_status, ['reviewed', 'assigned'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only reviewed consultations can be started.',
            ], 422);
        }

        if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== (int) Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'This consultation is already being handled by another physician.',
            ], 422);
        }

        $consultation->update([
            'request_status' => 'active',
            'assigned_physician_id' => Auth::id(),
        ]);

        ConsultationSession::firstOrCreate(
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

        return response()->json([
            'success' => true,
            'message' => 'Consultation started successfully.',
        ]);
    }

    private function getConsultationInboxData(): array
    {
        $assignedConsultations = Consultation::with(['patient', 'nurse'])
            ->whereIn('request_status', ['reviewed', 'assigned'])
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
                'file_attachments' => array_values($consultation->file_attachments ?? []),
            ];
        })->values()->all();
    }

    // public function approveReviewedConsultation(User $physician, Consultation $consultation)
    // {
    //     $this->authorizePhysician($physician);

    //     if ($consultation->request_status !== 'reviewed') {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Only reviewed consultations can be approved.',
    //         ], 422);
    //     }

    //     if ($consultation->assigned_physician_id && (int) $consultation->assigned_physician_id !== (int) Auth::id()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'This consultation is already assigned to another physician.',
    //         ], 422);
    //     }

    //     $consultation->update([
    //         'request_status' => 'assigned',
    //         'assigned_physician_id' => Auth::id(),
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Consultation approved successfully.',
    //     ]);
    // }

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
}
