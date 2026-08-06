<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('follow_up_requests', function (Blueprint $table) {
            $table->id();

            // Original completed consultation
            $table->foreignId('consultation_id')
                ->constrained('consultations')
                ->cascadeOnDelete();

            // Patient requesting the follow-up
            $table->foreignId('patient_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            // Nurse who screened the request
            $table->foreignId('reviewed_by_nurse_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            // Physician who made the final decision
            $table->foreignId('decided_by_physician_id')
                ->nullable()
                ->constrained('users', 'user_id')
                ->nullOnDelete();

            $table->text('reason');

            $table->enum('status', [
                'pending',
                'forwarded',
                'approved',
                'rejected',
                'cancelled',
                'expired',
            ])->default('pending');

            $table->text('decision_notes')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_up_requests');
    }
};