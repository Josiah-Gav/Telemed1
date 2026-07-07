<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Consultation;

class AttachmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Serve a consultation attachment to authorized nurses.
     */
    public function show(Consultation $consultation, $file)
    {
        // Only nurses may access attachments here
        if (Auth::user()->role !== 'nurse') {
            abort(403, 'Unauthorized access.');
        }
        $attachments = $consultation->file_attachments ?? [];

        foreach ($attachments as $path) {
            // Normalize filename for comparison
            $urlPath = parse_url($path, PHP_URL_PATH) ?: $path;
            $basename = basename($urlPath);
            if ($basename !== $file) {
                continue;
            }

            // If it's a remote URL (Cloudinary or other http(s)), redirect to it
            if (preg_match('#^https?://#i', $path)) {
                return redirect()->away($path);
            }

            // If it's an asset URL like /storage/..., extract relative storage path
            if (strpos($urlPath, '/storage/') !== false) {
                $relative = ltrim(substr($urlPath, strpos($urlPath, '/storage/') + strlen('/storage/')), '/');
                // Check public disk
                if (Storage::disk('public')->exists($relative)) {
                    return Storage::disk('public')->download($relative);
                }
            }

            // Fallback: try direct storage path
            if (Storage::exists($path)) {
                return Storage::download($path);
            }
        }

        abort(404);
    }
}
