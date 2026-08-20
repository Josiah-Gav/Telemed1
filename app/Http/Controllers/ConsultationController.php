<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Consultation;
use App\Models\SymptomLog; // Double-check that your SymptomLog model exists
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Support\Facades\Storage;
use App\Models\FollowUpRequest;
use App\Enums\NotificationType;
use App\Services\NotificationService;
use App\Services\ConsultationOwnershipService;
use App\Models\User;

class ConsultationController extends Controller
{
    public function __construct(private readonly ConsultationOwnershipService $ownershipService)
    {
    }

    /**
     * Display a listing of the consultations.
     */
    public function index()
    {
        // Fetch consultations for the authenticated user
        $consultations = Consultation::where('patient_id', auth()->id())->get();
        return view('consultations.index', compact('consultations'));
    }

    /**
     * Display the patient's consultation history.
     */
    public function history()
    {
        $dateFilter = (string) request()->query('date_filter', 'all');
        $statusFilter = (string) request()->query('status', 'all');
        $typeFilter = (string) request()->query('consultation_type', 'all');

        $allowedDateFilters = ['today', 'last_7_days', 'last_30_days', 'all'];
        $allowedStatusFilters = ['completed', 'cancelled', 'rejected', 'all'];
        $allowedTypeFilters = ['follow_up', 'general', 'all'];

        if (!in_array($dateFilter, $allowedDateFilters, true)) {
            $dateFilter = 'all';
        }

        if (!in_array($statusFilter, $allowedStatusFilters, true)) {
            $statusFilter = 'all';
        }

        if (!in_array($typeFilter, $allowedTypeFilters, true)) {
            $typeFilter = 'all';
        }

        $consultations = Consultation::query()
            ->where('patient_id', auth()->id())
            ->where(function ($query) {
                $query->whereIn('request_status', ['completed', 'rejected', 'cancelled'])
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->where('consultation_status', 'completed');
                    });
            })
            ->when($statusFilter !== 'all', function ($query) use ($statusFilter) {
                $query->where('request_status', $statusFilter);
            })
            ->when($typeFilter === 'follow_up', function ($query) {
                $query->where('type', 'follow_up');
            })
            ->when($typeFilter === 'general', function ($query) {
                $query->where(function ($typeQuery) {
                    $typeQuery->whereNull('type')->orWhere('type', '!=', 'follow_up');
                });
            })
            ->when($dateFilter === 'today', function ($query) {
                $query->whereDate('submitted_at', now()->toDateString());
            })
            ->when($dateFilter === 'last_7_days', function ($query) {
                $query->where('submitted_at', '>=', now()->subDays(7)->startOfDay());
            })
            ->when($dateFilter === 'last_30_days', function ($query) {
                $query->where('submitted_at', '>=', now()->subDays(30)->startOfDay());
            })
            ->latest('submitted_at')
            ->get();

        $rejectedFollowUpRequests = FollowUpRequest::query()
            ->with(['consultation.request'])
            ->where('patient_id', auth()->id())
            ->where('status', 'rejected')
            ->when($statusFilter !== 'all', function ($query) use ($statusFilter) {
                if ($statusFilter !== 'rejected') {
                    $query->whereRaw('1 = 0');
                }
            })
            ->when($typeFilter === 'general', function ($query) {
                $query->whereRaw('1 = 0');
            })
            ->when($dateFilter === 'today', function ($query) {
                $query->whereDate('updated_at', now()->toDateString());
            })
            ->when($dateFilter === 'last_7_days', function ($query) {
                $query->where('updated_at', '>=', now()->subDays(7)->startOfDay());
            })
            ->when($dateFilter === 'last_30_days', function ($query) {
                $query->where('updated_at', '>=', now()->subDays(30)->startOfDay());
            })
            ->latest('updated_at')
            ->get();

        $historyItems = $consultations
            ->map(function (Consultation $consultation) {
                return [
                    'type' => 'consultation',
                    'sort_at' => $consultation->submitted_at,
                    'consultation' => $consultation,
                ];
            })
            ->concat(
                $rejectedFollowUpRequests->map(function (FollowUpRequest $followUpRequest) {
                    return [
                        'type' => 'rejected_follow_up_request',
                        'sort_at' => $followUpRequest->updated_at,
                        'follow_up_request' => $followUpRequest,
                    ];
                })
            )
            ->sortByDesc(function (array $item) {
                return optional($item['sort_at'])->timestamp ?? 0;
            })
            ->values();

        $filters = [
            'date_filter' => $dateFilter,
            'status' => $statusFilter,
            'consultation_type' => $typeFilter,
        ];

        return view('patient.consultation-history', compact('consultations', 'rejectedFollowUpRequests', 'historyItems', 'filters'));
    }

    /**
     * Show the form for creating a new consultation.
     */
    public function create()
    {
        $patient = auth()->user();

        if ($patient->role !== 'patient') {
            abort(403, 'Unauthorized access.');
        }

        $hasActiveConsultation = Consultation::where('patient_id', auth()->id())
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->whereIn('consultation_status', ['scheduled', 'active']);
                    });
            })
            ->exists();

        if ($hasActiveConsultation) {
            return redirect()->route('dashboard')->with('status', 'You already have an active consultation request.');
        }

        return view('patient.newconsultation', compact('patient'));
    }

    /**
     * Store a newly created consultation request in storage (Called on Step 4 submission).
     */
    public function store(Request $request)
    {
        // 1. Enforce one active consultation request per patient
        $existingActiveConsultation = Consultation::where('patient_id', auth()->id())
            ->whereIn('request_status', ['pending', 'reviewed', 'assigned', 'scheduled', 'active'])
            ->where(function ($query) {
                $query->whereDoesntHave('consultationSession')
                    ->orWhereHas('consultationSession', function ($sessionQuery) {
                        $sessionQuery->whereIn('consultation_status', ['scheduled', 'active']);
                    });
            })
            ->exists();

        if ($existingActiveConsultation) {
            return response()->json([
                'success' => false,
                'message' => 'You may only have one active consultation request at a time.',
            ], 422);
        }

        // 2. Validate the form inputs
        $validated = $request->validate([
            'concern_category' => 'required|string|max:100',
            'symptoms_payload' => 'required|string', 
            'online_reason'    => 'required|string|max:1000',
            'attachments.*'    => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB Limit
        ]);

        // 3. Decode alpine symptom list tracking payload 
        $symptomsData = json_decode($validated['symptoms_payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($symptomsData) || count($symptomsData) === 0) {
            return response()->json(['success' => false, 'message' => 'Please provide at least one symptom.'], 422);
        }

        // 3. Process uploads. Prefer Cloudinary, but fall back to local storage if it fails.
        $uploadedFilesUrls = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                try {
                    $uploadResult = Cloudinary::uploadApi()->upload($file->getRealPath(), [
                        'folder' => 'telemed_consultations',
                        'resource_type' => 'auto',
                    ]);

                    $uploadedFilesUrls[] = $uploadResult['secure_url'] ?? ($uploadResult['url'] ?? null);
                } catch (\Exception $uploadError) {
                    Log::error('Cloudinary Single Upload Error: ' . $uploadError->getMessage());

                    $path = $file->store('consultation-attachments', 'public');
                    $uploadedFilesUrls[] = asset('storage/' . $path);
                }
            }
        }

        try {
            // 4. Record details using your modified database column structure
            $consultation = Consultation::create([
                'patient_id'            => auth()->id(),
                'assigned_physician_id' => null,
                'assigned_nurse_id'     => null,
                'concern_category'      => $validated['concern_category'],
                'symptoms_desc'         => $symptomsData,
                'online_reason'         => $validated['online_reason'] ?? null,
                'file_attachments'      => !empty($uploadedFilesUrls) ? $uploadedFilesUrls : null, // Securely stores the remote Cloudinary cloud link array
                'request_status'        => 'pending',
            ]);

            NotificationService::sendToRole(
                'nurse',
                NotificationType::CONSULTATION_SUBMITTED,
                'New Consultation Request',
                'A new consultation request requires your review.',
                [
                    'consultation_id' => $consultation->request_id,
                    'request_id' => $consultation->request_id,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Consultation request created and backed up to cloud successfully.',
                'data'    => $consultation
            ], 201);

        } catch (\Exception $e) {
            Log::error('Consultation submission failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Server error encountered.'], 500);
        }
    }

    /**
     * Display the details of a consultation.
     */
    public function show(Consultation $consultation)
    {
        abort_unless(Gate::allows('view', $consultation), 403, 'Unauthorized access.');

        $consultation->load(['nurse', 'consultationSession.slot']);

        return view('patient.consultation-details', compact('consultation'));
    }


    function rejectionConsultation(Request $request, Consultation $consultation)
    {
        // Validate the rejection reason
        $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        try {
            $consultation = $this->ownershipService->rejectByNurse(
                (int) $consultation->request_id,
                (string) $request->input('rejection_reason')
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
            'Consultation Request Rejected',
            'Your consultation request was rejected. Reason: ' . $request->input('rejection_reason'),
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'rejected' => true,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Consultation request rejected successfully.']);
    }

    function approveConsultation(Request $request, Consultation $consultation)
    {
        $validated = $request->validate([
            'priority_level' => 'required|in:High,Normal',
        ]);

        try {
            $consultation = $this->ownershipService->claimByNurse(
                (int) $consultation->request_id,
                (int) auth()->id(),
                (string) $validated['priority_level']
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Notify the patient that their request was reviewed.
        NotificationService::send(
            $consultation->patient_id,
            NotificationType::CONSULTATION_REVIEWED,
            'Consultation Reviewed',
            'Your consultation request has been reviewed by the infirmary staff.',
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
            ]
        );

        // Notify all physicians that a new consultation is ready for assignment.
        $isHighPriority = strtolower((string) $validated['priority_level']) === 'high';
        NotificationService::sendToRole(
            'physician',
            $isHighPriority ? NotificationType::HIGH_PRIORITY_CONSULTATION : NotificationType::CONSULTATION_ASSIGNED,
            $isHighPriority ? 'High-Priority Consultation' : 'New Consultation Available',
            $isHighPriority
                ? 'A high-priority consultation has been approved and is waiting for a physician.'
                : 'A new consultation has been approved and is waiting for a physician.',
            [
                'consultation_id' => $consultation->request_id,
                'request_id' => $consultation->request_id,
                'priority_level' => $consultation->priority_level,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Consultation request approved successfully.']);
    }

    function cancelConsultation(Request $request, Consultation $consultation)
    {
        // Ensure the consultation belongs to the authenticated user
        if ($consultation->patient_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized action.'], 403);
        }

        try {
            $consultation = $this->ownershipService->cancelByPatient(
                (int) $consultation->request_id,
                (int) auth()->id()
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Notify the assigned nurse (if any) that the patient cancelled the request.
        if ($consultation->assigned_nurse_id) {
            NotificationService::send(
                $consultation->assigned_nurse_id,
                NotificationType::SYSTEM_ALERT,
                'Consultation Cancelled',
                'A patient cancelled their consultation request.',
                [
                    'consultation_id' => $consultation->request_id,
                    'request_id' => $consultation->request_id,
                ]
            );
        }

        return response()->json(['success' => true, 'message' => 'Consultation request cancelled successfully.']);
    }

    // You can leave edit, update, and destroy empty or remove them if unused!
}
