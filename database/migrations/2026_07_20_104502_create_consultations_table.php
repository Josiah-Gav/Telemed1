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
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();

            // Foreign Keys
            $table->foreignId('request_id')
                ->references('request_id')
                ->on('consultation_requests')
                ->cascadeOnDelete();

            $table->foreignId('physician_id')
                ->nullable()
                ->references('user_id')
                ->on('users')
                ->nullOnDelete();

            // Consultation Details
            $table->enum('consultation_status', [
                'active',
                'completed',
                'cancelled'
            ])->default('active');

            $table->text('assessment');

            $table->text('plan');

            $table->text('recommendations');

            $table->string('diagnosis')->nullable();

            $table->text('cancellation_reason')->nullable();

            $table->boolean('follow_up_required')->default(false);

            $table->date('follow_up_date')->nullable();

            // Timestamps for workflow
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            // Laravel timestamps
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};