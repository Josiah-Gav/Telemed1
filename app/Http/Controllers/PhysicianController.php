<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
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

        $assignedConsultations = Consultation::with(['patient', 'nurse'])
            ->where('request_status', 'assigned')
            ->orderByDesc('submitted_at')
            ->get();

        $normalPriorityConsultations = $assignedConsultations
            ->where('priority_level', 'Normal')
            ->values();

        $highPriorityConsultations = $assignedConsultations
            ->where('priority_level', 'High')
            ->values();

        return view('physician.consultation_inbox', [
            'physician' => $physician,
            'normalPriorityConsultations' => $normalPriorityConsultations,
            'highPriorityConsultations' => $highPriorityConsultations,
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

        if ($consultation->request_status !== 'assigned') {
            return response()->json([
                'success' => false,
                'message' => 'Only assigned consultations can be started.',
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

        return response()->json([
            'success' => true,
            'message' => 'Consultation started successfully.',
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

        return view('physician.consultation_history');
    }

    public function activeConsultations(User $physician)
    {
        $this->authorizePhysician($physician);

        $activeConsultations = Consultation::with(['patient', 'nurse'])
            ->where('request_status', 'active')
            ->where('assigned_physician_id', Auth::id())
            ->orderByDesc('submitted_at')
            ->get();

        return view('physician.active_consultation', [
            'physician' => $physician,
            'activeConsultations' => $activeConsultations,
        ]);
    }
}
