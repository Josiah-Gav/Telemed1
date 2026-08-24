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
        Schema::create('consultation_video_sessions', function (Blueprint $table) {
            $table->id();

            // Video sessions belong to the clinical session (consultations.id),
            // not to the patient-facing request (consultation_requests.request_id).
            // Matches how consultation_messages and follow_up_requests name this key.
            $table->foreignId('consultation_id')
                ->references('id')
                ->on('consultations')
                ->cascadeOnDelete();

            $table->string('room_name')->unique();

            // NULL marks the one currently active video session; a timestamp marks
            // a historical one. The "at most one active" rule is enforced by the
            // parent-row pessimistic lock in the service layer, because a partial
            // unique index (UNIQUE ... WHERE ended_at IS NULL) is unsupported on MySQL.
            $table->dateTime('ended_at')->nullable();

            $table->timestamps();

            // Serves the active-session lookup: where consultation_id = ? and ended_at is null.
            $table->index(['consultation_id', 'ended_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_video_sessions', function (Blueprint $table) {
            $table->dropForeign(['consultation_id']);
        });

        Schema::dropIfExists('consultation_video_sessions');
    }
};
