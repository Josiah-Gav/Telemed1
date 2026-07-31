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
                $activeConsultation = $this->getPatientActiveConsultation($patientInfo->user_id);
                $activeConsultationSummary = $this->getConsultationSummary($activeConsultation);

                return view('patient.dashboard', compact('patientInfo', 'activeConsultation', 'activeConsultationSummary'));
            case 'physician':
                return view('physician.dashboard');
            case 'nurse':
                return redirect()->route('nurse.dashboard', ['nurse' => $user]);
            case 'admin':
                return view('admin.dashboard');
            default:
                abort(403, 'Unauthorized action. Role not recognized.');
        }
    }

    public function activeConsultation()
    {
        $patient = Auth::user();

        if ($patient->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $consultation = $this->getPatientActiveConsultation($patient->user_id);

        return response()->json([
            'consultation' => $this->serializePatientConsultation($consultation),
        ]);
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

    private function getPatientActiveConsultation(int $patientId): ?Consultation
    {
        return Consultation::with('consultationSession')
            ->where('patient_id', $patientId)
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->where('consultation_status', 'active');
                    });
            })
            ->latest('submitted_at')
            ->first();
    }

    private function getConsultationSummary(?Consultation $consultation): ?string
    {
        if (!$consultation) {
            return null;
        }

        if (is_array($consultation->symptoms_desc)) {
            return collect($consultation->symptoms_desc)
                ->pluck('name')
                ->filter()
                ->join(', ');
        }

        return $consultation->symptoms_desc;
    }

    private function serializePatientConsultation(?Consultation $consultation): ?array
    {
        if (!$consultation) {
            return null;
        }

        $status = $consultation->request_status;
        $consultationSession = $consultation->consultationSession;

        return [
            'request_id' => $consultation->request_id,
            'show' => true,
            'show_messaging' => in_array($status, ['active', 'completed'], true) && $consultationSession,
            'details_url' => route('consultations.show', $consultation),
            'concern_category' => $consultation->concern_category,
            'summary' => $this->getConsultationSummary($consultation) ?: 'No symptoms recorded',
            'request_status' => $status,
            'submitted_at' => optional($consultation->submitted_at)->format('M d, Y'),
            'status_badge_class' => $this->getPatientStatusBadgeClass($status),
            'status_label' => ucfirst($status),
            'session' => $consultationSession ? [
                'id' => $consultationSession->id,
                'consultation_status' => $consultationSession->consultation_status,
                'has_clinical_documentation' => $consultationSession->hasClinicalDocumentation(),
                'clinical_badge_class' => $consultationSession->hasClinicalDocumentation() ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700',
                'clinical_label' => $consultationSession->hasClinicalDocumentation() ? __('Assessment ready') : __('Assessment pending'),
                'has_prescription' => $consultationSession->hasPrescription(),
                'prescription_badge_class' => $consultationSession->hasPrescription() ? 'bg-indigo-100 text-indigo-700' : 'bg-slate-100 text-slate-600',
                'prescription_label' => $consultationSession->hasPrescription() ? __('Prescription uploaded') : __('No prescription'),
            ] : null,
        ];
    }

    private function getPatientStatusBadgeClass(string $status): string
    {
        $statusClasses = 'inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold ';

        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return $statusClasses . 'bg-red-100 text-red-700';
        }

        if ($status === 'completed') {
            return $statusClasses . 'bg-emerald-100 text-emerald-700';
        }

        if (in_array($status, ['pending', 'assigned'], true)) {
            return $statusClasses . 'bg-yellow-100 text-yellow-700';
        }

        if ($status === 'scheduled') {
            return $statusClasses . 'bg-indigo-100 text-indigo-700';
        }

        if ($status === 'active') {
            return $statusClasses . 'bg-blue-100 text-blue-700';
        }

        return $statusClasses . 'bg-slate-100 text-slate-700';
    }
}
