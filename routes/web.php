<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConsultationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// --- EVERYTHING INSIDE THIS BLOCK REQUIRES LOGIN ---
Route::middleware(['auth', 'verified'])->group(function () {
    
    // 1. Role-based universal dashboard entry point
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

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

    // Attachment download for consultations (nurse only access validated in controller)
    Route::get('/consultations/{consultation}/attachments/{file}', [\App\Http\Controllers\AttachmentController::class, 'show'])
        ->name('consultation.attachment');

    Route::post('/consultations/{consultation}/reject', [ConsultationController::class, 'rejectionConsultation'])
    ->name('consultations.reject');

    Route::post('/consultations/{consultation}/approve', [ConsultationController::class, 'approveConsultation'])
    ->name('consultations.approve');

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
