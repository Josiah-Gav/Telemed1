<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationSession;
use App\Models\FollowUpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FollowUpRequestController extends Controller
{
    public function index()
    {
        $patient = Auth::user();

        if ($patient->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $completedConsultations = ConsultationSession::with(['request.patient', 'physician', 'slot', 'followUpRequests'])
            ->whereHas('request', function ($query) use ($patient) {
                $query->where('patient_id', $patient->user_id);
            })
            ->where('consultation_status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(7))
            ->orderByDesc('completed_at')
            ->get();

        return view('patient.follow_up_list', [
            'patient' => $patient,
            'completedConsultations' => $completedConsultations,
        ]);
    }

    public function store(Request $request, ConsultationSession $session)
    {
        $patient = Auth::user();

        if ($patient->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $session->loadMissing('request');

        if (!$session->request || (int) $session->request->patient_id !== (int) $patient->user_id) {
            abort(403, 'Unauthorized access.');
        }

        if ($session->consultation_status !== 'completed' || !$session->completed_at) {
            return back()->withErrors(['reason' => 'Follow-up can only be requested for completed consultations.']);
        }

        if ($session->completed_at->lt(now()->subDays(7))) {
            return back()->withErrors(['reason' => 'Follow-up requests are only allowed within 7 days of completion.']);
        }

        $hasActiveFollowUpRequest = FollowUpRequest::query()
            ->where('consultation_id', $session->id)
            ->whereIn('status', ['pending', 'forwarded', 'approved'])
            ->exists();

        if ($hasActiveFollowUpRequest) {
            return back()->withErrors(['reason' => 'A follow-up request already exists for this consultation.']);
        }

        $hasActiveFollowUpConsultation = Consultation::query()
            ->where('type', 'follow_up')
            ->where('parent_consultation_id', $session->id)
            ->whereIn('request_status', ['pending', 'scheduled', 'active'])
            ->exists();

        if ($hasActiveFollowUpConsultation) {
            return back()->withErrors(['reason' => 'A follow-up consultation is already in progress for this consultation.']);
        }

        DB::transaction(function () use ($session, $patient, $validated) {
            FollowUpRequest::create([
                'consultation_id' => $session->id,
                'patient_id' => $patient->user_id,
                'reason' => $validated['reason'],
                'status' => 'pending',
            ]);
        });

        // TODO: Notify nurse

        return redirect()
            ->route('patient.follow_up_list')
            ->with('status', 'Your follow-up request has been submitted for review.');
    }
}