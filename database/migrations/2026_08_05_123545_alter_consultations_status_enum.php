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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE consultations
            MODIFY COLUMN consultation_status ENUM(
                'scheduled',
                'active',
                'completed',
                'cancelled'
            ) NOT NULL DEFAULT 'scheduled'
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
            ALTER TABLE consultations
            MODIFY COLUMN consultation_status ENUM(
                'active',
                'completed',
                'cancelled'
            ) NOT NULL DEFAULT 'active'
        ");
    }
};