<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physician Takeover metadata.
 *
 * Takeover deliberately introduces no new status value: a taken-over
 * consultation stays request_status = 'scheduled' / consultation_status =
 * 'scheduled' and simply changes hands. These three columns are the whole
 * record of that hand-off, and they live on `consultations` (the session
 * table) alongside the assigned_at/started_at/completed_at trio that already
 * carries this model's lifecycle audit.
 *
 * The remaining audit facts need no columns because they are already
 * derivable: original assignment time is assigned_at, the scheduled time is
 * the joined schedule_slots row, the moment takeover became eligible is that
 * slot's start plus ConsultationOwnershipService::TAKEOVER_GRACE_MINUTES, and
 * the physician who actually started is physician_id together with started_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            // The physician the consultation was scheduled to before any
            // takeover. Written once, on the first takeover, and never
            // overwritten afterwards.
            $table->unsignedBigInteger('original_physician_id')
                ->nullable()
                ->after('physician_id');

            // The physician who claimed the consultation. Equal to
            // physician_id today; kept separate so "who claimed it" stays
            // distinguishable from "who ended up running it".
            $table->unsignedBigInteger('taken_over_by_physician_id')
                ->nullable()
                ->after('original_physician_id');

            $table->dateTime('taken_over_at')
                ->nullable()
                ->after('taken_over_by_physician_id');

            $table->foreign('original_physician_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('taken_over_by_physician_id')
                ->references('user_id')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropForeign(['original_physician_id']);
            $table->dropForeign(['taken_over_by_physician_id']);
            $table->dropColumn([
                'original_physician_id',
                'taken_over_by_physician_id',
                'taken_over_at',
            ]);
        });
    }
};
