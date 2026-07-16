<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Consultation;
use App\Models\User;

class NurseController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    private function authorizeNurse(User $nurse)
    {
        if (Auth::user()->role !== 'nurse' || Auth::id() !== $nurse->user_id) {
            abort(403, 'Unauthorized access.');
        }
    }

    public function dashboard(User $nurse)
    {
        $this->authorizeNurse($nurse);

        return view('nurse.dashboard', [
            'nurse' => $nurse,
        ]);
    }

    public function consultationInbox(User $nurse)
    {
        $this->authorizeNurse($nurse);

        $currentNurseId = (int) $nurse->user_id;

        $pendingRequests = Consultation::with('patient')
            ->where('request_status', 'pending')
            ->orderByDesc('submitted_at')
            ->get();

        $assignedRequests = Consultation::with(['patient', 'nurse'])
            ->whereIn('request_status', ['assigned', 'active', 'scheduled'])
            ->orderByDesc('submitted_at')
            ->get();

        $assignedToCurrentNurse = $assignedRequests
            ->where('assigned_nurse_id', $currentNurseId)
            ->values();

        $assignedToOtherNurses = $assignedRequests
            ->filter(fn ($request) => (int) $request->assigned_nurse_id !== $currentNurseId)
            ->values();

        return view('nurse.consultation_inbox', [
            'nurse' => $nurse,
            'pendingRequests' => $pendingRequests,
            'assignedToCurrentNurse' => $assignedToCurrentNurse,
            'assignedToOtherNurses' => $assignedToOtherNurses,
        ]);
    }

    public function followUpRequests(User $nurse)
    {
        $this->authorizeNurse($nurse);

        return view('nurse.follow_up_requests', [
            'nurse' => $nurse,
        ]);
    }

    public function consultationHistory(User $nurse)
    {
        $this->authorizeNurse($nurse);

        return view('nurse.consultation_history', [
            'nurse' => $nurse,
        ]);
    }
}
