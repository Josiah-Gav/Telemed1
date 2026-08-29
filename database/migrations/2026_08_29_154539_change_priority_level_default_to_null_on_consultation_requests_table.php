<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // priority_level is only ever explicitly set by
        // ConsultationController::approveConsultation() -> claimByNurse()
        // (or inherited from an already-triaged parent on follow-up
        // creation) — never at initial patient submission. The 'Normal'
        // default meant a brand-new, not-yet-reviewed request silently
        // looked like a nurse had already triaged it as Normal priority.
        // Same MySQL-only enum ALTER pattern as
        // alter_consultations_status_enum.php — SQLite (the test DB) never
        // enforces this and is skipped, matching CLAUDE.md's documented
        // gotcha for enum migrations.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE consultation_requests
            MODIFY COLUMN priority_level ENUM('High', 'Normal') NULL DEFAULT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE consultation_requests
            MODIFY COLUMN priority_level ENUM('High', 'Normal') NOT NULL DEFAULT 'Normal'
        ");
    }
};
