<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Consultation;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Check the user's role and return the corresponding view
        // Note: This assumes you have a 'role' column on your users table
        switch ($user->role) {
            case 'patient':
                $patientInfo = Auth::user();
                $activeConsultation = Consultation::with('consultationSession')
                    ->where('patient_id', auth()->id())
                    ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
                    ->where(function ($query) {
                        $query->whereDoesntHave('consultationSession')
                            ->orWhereHas('consultationSession', function ($sessionQuery) {
                                $sessionQuery->where('consultation_status', 'active');
                            });
                    })
                    ->latest('submitted_at')
                    ->first();

                $activeConsultationSummary = null;
                if ($activeConsultation) {
                    if (is_array($activeConsultation->symptoms_desc)) {
                        $activeConsultationSummary = collect($activeConsultation->symptoms_desc)
                            ->pluck('name')
                            ->filter()
                            ->join(', ');
                    } else {
                        $activeConsultationSummary = $activeConsultation->symptoms_desc;
                    }
                }

                return view('patient.dashboard', compact('patientInfo', 'activeConsultation', 'activeConsultationSummary'));
            case 'physician':
                return view('physician.dashboard');
            case 'nurse':
                return redirect()->route('nurse.dashboard', ['nurse' => $user]);
            default:
                abort(403, 'Unauthorized action. Role not recognized.');
        }
    }
    
    public function newconsultation()
    {
        $patientInfo = Auth::user();

        // Security checkpoint: block physicians or nurses if they try to access this page
        if ($patientInfo->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $hasActiveConsultation = \App\Models\Consultation::where('patient_id', auth()->id())
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->where('consultation_status', 'active');
                    });
            })
            ->exists();

        if ($hasActiveConsultation) {
            return redirect()->route('dashboard')->with('status', 'You already have an active consultation request.');
        }

        return view('patient.newconsultation')->with('patient', $patientInfo);
    }
}
