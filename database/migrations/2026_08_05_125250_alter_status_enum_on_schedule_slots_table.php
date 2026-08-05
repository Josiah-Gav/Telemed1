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
            ALTER TABLE schedule_slots
            MODIFY COLUMN status ENUM(
                'available',
                'booked',
                'missed',
                'completed'
            ) NOT NULL DEFAULT 'available'
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
            ALTER TABLE schedule_slots
            MODIFY COLUMN status ENUM(
                'available',
                'booked'
            ) NOT NULL DEFAULT 'available'
        ");
    }
};