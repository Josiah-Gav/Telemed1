<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `users.user_type` (student/staff/faculty) is no longer collected through
     * any form — patient self-registration and the admin "create staff" /
     * "edit user" pages stopped setting or exposing it. New patients are now
     * created with no value at all rather than a guessed 'student', so the
     * column has to accept NULL. Existing rows keep whatever value they
     * already had.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN user_type ENUM('student', 'staff', 'faculty') NULL
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

        // Any row left NULL by the nullable period would violate a NOT NULL
        // revert, so those are backfilled to the same 'student' default the
        // column originally shipped with before the constraint is restored.
        DB::statement("UPDATE users SET user_type = 'student' WHERE user_type IS NULL");

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN user_type ENUM('student', 'staff', 'faculty') NOT NULL
        ");
    }
};
