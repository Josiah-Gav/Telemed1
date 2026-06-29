<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Consultation;
use App\Models\SymptomLog; // Double-check that your SymptomLog model exists
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Support\Facades\Storage;

class ConsultationController extends Controller
{
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
     * Show the form for creating a new consultation.
     */
    public function create()
    {
        // Mocking the patient data context for the Blade layout variables
        // Adjust this to point to your actual patient profile/user relationship
        $patient = auth()->user(); 

        return view('newconsultation', compact('patient'));
    }

    /**
     * Store a newly created consultation request in storage (Called on Step 4 submission).
     */
    public function store(Request $request)
    {
        // 1. Enforce one active consultation request per patient
        $existingActiveConsultation = Consultation::where('patient_id', auth()->id())
            ->whereIn('request_status', ['pending', 'assigned', 'scheduled', 'active'])
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
            'attachments.*'    => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB Limit
        ]);

        // 3. Decode alpine symptom list tracking payload 
        $symptomsData = json_decode($validated['symptoms_payload'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($symptomsData)) {
            return response()->json(['success' => false, 'message' => 'Invalid symptoms format.'], 422);
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
                'file_attachments'      => !empty($uploadedFilesUrls) ? $uploadedFilesUrls : null, // Securely stores the remote Cloudinary cloud link array
                'request_status'        => 'pending',
            ]);

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
        if ($consultation->patient_id !== auth()->id()) {
            abort(403, 'Unauthorized access.');
        }

        return view('patient.consultation-details', compact('consultation'));
    }

    // You can leave edit, update, and destroy empty or remove them if unused!
}
