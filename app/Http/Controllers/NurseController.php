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

    private function getConsultationInboxData(User $nurse): array
    {
        $currentNurseId = (int) $nurse->user_id;

        $pendingRequests = Consultation::with('patient')
            ->where('request_status', 'pending')
            ->orderByDesc('submitted_at')
            ->get();

        $assignedRequests = Consultation::with(['patient', 'nurse'])
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
                'concern_category' => $request->concern_category,
                'submitted_at' => $request->submitted_at ? $request->submitted_at->format('Y-m-d H:i') : null,
                'request_status' => $request->request_status,
                'assigned_nurse_id' => $request->assigned_nurse_id,
                'assigned_nurse_name' => trim(optional($request->nurse)->first_name . ' ' . optional($request->nurse)->last_name) ?: null,
                'priority_level' => $request->priority_level,
                'symptoms_desc' => $request->symptoms_desc,
                'online_reason' => $request->online_reason,
                'file_attachments' => array_map(function ($path) use ($request) {
                    return url('/consultations/' . $request->request_id . '/attachments/' . basename($path));
                }, $request->file_attachments ?? []),
            ];
        })->values()->all();
    }
}
