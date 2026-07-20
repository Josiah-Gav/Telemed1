<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\ConsultationMessageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// --- EVERYTHING INSIDE THIS BLOCK REQUIRES LOGIN ---
Route::middleware(['auth', 'verified'])->group(function () {
    
    // 1. Role-based universal dashboard entry point
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/admin/users', [\App\Http\Controllers\Admin\UserManagementController::class, 'index'])
        ->name('admin.users.index');
    Route::get('/admin/users/create', [\App\Http\Controllers\Admin\UserManagementController::class, 'create'])
        ->name('admin.users.create');
    Route::post('/admin/users', [\App\Http\Controllers\Admin\UserManagementController::class, 'store'])
        ->name('admin.users.store');
    Route::get('/admin/users/{user}/edit', [\App\Http\Controllers\Admin\UserManagementController::class, 'edit'])
        ->name('admin.users.edit');
    Route::put('/admin/users/{user}', [\App\Http\Controllers\Admin\UserManagementController::class, 'update'])
        ->name('admin.users.update');

    // 2. Safe placement for your new consultation page
    Route::get('/newconsultation', [DashboardController::class, 'newconsultation'])
        ->name('newconsultation');

    // Nurse-specific navigation pages
    Route::prefix('nurses/{nurse}')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\NurseController::class, 'dashboard'])
            ->name('nurse.dashboard');

        Route::get('/consultation-inbox', [\App\Http\Controllers\NurseController::class, 'consultationInbox'])
            ->name('nurse.consultation_inbox');

        Route::get('/follow-up-requests', [\App\Http\Controllers\NurseController::class, 'followUpRequests'])
            ->name('nurse.follow_up_requests');

        Route::get('/consultation-history', [\App\Http\Controllers\NurseController::class, 'consultationHistory'])
            ->name('nurse.consultation_history');
    });

    //Physician-specific navigation pages
    Route::prefix('physicians/{physician}')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\PhysicianController::class, 'dashboard'])
            ->name('physician.dashboard');
        Route::get('/consultation-inbox', [\App\Http\Controllers\PhysicianController::class, 'consultationInbox'])
            ->name('physician.consultation_inbox');
        Route::post('/consultations/{consultation}/approve-reviewed', [\App\Http\Controllers\PhysicianController::class, 'approveReviewedConsultation'])
            ->name('physician.consultations.approve_reviewed');
        Route::post('/consultations/{consultation}/reject-reviewed', [\App\Http\Controllers\PhysicianController::class, 'rejectReviewedConsultation'])
            ->name('physician.consultations.reject_reviewed');
        Route::post('/consultations/{consultation}/start', [\App\Http\Controllers\PhysicianController::class, 'startConsultation'])
            ->name('physician.consultations.start');
        Route::get('/follow-up-requests', [\App\Http\Controllers\PhysicianController::class, 'followUpRequests'])
            ->name('physician.follow_up_requests');
        Route::get('/consultation-history', [\App\Http\Controllers\PhysicianController::class, 'consultationHistory'])
            ->name('physician.consultation_history');
        Route::get('/active_consultation', [\App\Http\Controllers\PhysicianController::class, 'activeConsultations'])
            ->name('physician.active_consultation');
    });

    // Attachment download for consultations (nurse only access validated in controller)
    Route::get('/consultations/{consultation}/attachments/{file}', [\App\Http\Controllers\AttachmentController::class, 'show'])
        ->name('consultation.attachment');

    Route::post('/consultations/{consultation}/reject', [ConsultationController::class, 'rejectionConsultation'])
    ->name('consultations.reject');

    Route::post('/consultations/{consultation}/approve', [ConsultationController::class, 'approveConsultation'])
    ->name('consultations.approve');

    Route::post('/consultations/{consultation}/cancel', [ConsultationController::class, 'cancelConsultation'])
    ->name('consultations.cancel');

    Route::get('/consultation-sessions/{session}/messaging', [ConsultationMessageController::class, 'show'])
        ->name('consultations.messaging.show');
    Route::get('/consultation-sessions/{session}/messages', [ConsultationMessageController::class, 'index'])
        ->name('consultations.messaging.index');
    Route::post('/consultation-sessions/{session}/messages', [ConsultationMessageController::class, 'store'])
        ->name('consultations.messaging.store');
    Route::post('/consultation-sessions/{session}/messages/read', [ConsultationMessageController::class, 'markRead'])
        ->name('consultations.messaging.read');
    Route::post('/consultation-sessions/{session}/clinical-details', [ConsultationMessageController::class, 'updateClinicalDetails'])
        ->name('consultations.messaging.clinical_details.update');
    Route::post('/consultation-sessions/{session}/complete', [ConsultationMessageController::class, 'complete'])
        ->name('consultations.messaging.complete');
    Route::get('/consultation-sessions/unread-counts', [ConsultationMessageController::class, 'unreadCounts'])
        ->name('consultations.messaging.unread_counts');
    Route::post('/consultation-sessions/{session}/typing', [ConsultationMessageController::class, 'typing'])
        ->name('consultations.messaging.typing');
    Route::get('/consultation-sessions/{session}/presence', [ConsultationMessageController::class, 'presence'])
        ->name('consultations.messaging.presence');
    Route::get('/consultation-sessions/{session}/prescription/download', [ConsultationMessageController::class, 'downloadPrescription'])
        ->name('consultations.messaging.prescription.download');
    Route::get('/consultation-message-attachments/{attachment}/download', [ConsultationMessageController::class, 'downloadAttachment'])
        ->name('consultations.messaging.attachments.download');

}); // --- MIDDLEWARE GROUP ENDS HERE ---

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/consultations/history', [ConsultationController::class, 'history'])->name('consultations.history');
    Route::get('/consultations/create', [ConsultationController::class, 'create'])->name('consultations.create');
    Route::post('/consultations', [ConsultationController::class, 'store'])->name('consultations.store');
    Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
});

require __DIR__.'/auth.php';
