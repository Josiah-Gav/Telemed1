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
     * Consultation request statuses that make up the physician consultation
     * inbox's shared triage pool. PhysicianController::getConsultationInboxData
     * deliberately applies no assigned_physician_id filter, so every physician
     * can already see these requests and their attachments in the inbox modal.
     */
    private const PHYSICIAN_POOL_STATUSES = ['reviewed', 'assigned', 'scheduled'];

    /**
     * Serve a consultation attachment to the staff who are allowed to see it.
     */
    public function show(Consultation $consultation, $file)
    {
        if (! $this->canViewAttachments($consultation)) {
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

    /**
     * Nurses keep blanket access (unchanged). A physician gets access only to
     * what their inbox already shows them: a request still in the shared triage
     * pool, or one assigned to them personally at any status. A request that
     * has left the pool and belongs to another physician is off limits, and so
     * is every other role — patients included, who reach their own attachments
     * through ConsultationController instead.
     */
    private function canViewAttachments(Consultation $consultation): bool
    {
        $user = Auth::user();

        if ($user->role === 'nurse') {
            return true;
        }

        if ($user->role !== 'physician') {
            return false;
        }

        return (int) $consultation->assigned_physician_id === (int) $user->user_id
            || in_array($consultation->request_status, self::PHYSICIAN_POOL_STATUSES, true);
    }
}
