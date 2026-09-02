<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     *
     * Unlike the two other enum ALTERs documented in CLAUDE.md — which skip
     * SQLite because SQLite's `enum()` column is a plain varchar with no
     * CHECK constraint, so it never rejected their extra values anyway —
     * this one changes NOT NULL, which SQLite *does* enforce structurally.
     * A no-op here would leave the test database unable to insert the NULL
     * this migration exists to allow, so it gets a real (native, no
     * doctrine/dbal) column change instead of the usual early return.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('user_type')->nullable()->change();
            });

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
        // Any row left NULL by the nullable period would violate a NOT NULL
        // revert, so those are backfilled to the same 'student' default the
        // column originally shipped with before the constraint is restored.
        DB::statement("UPDATE users SET user_type = 'student' WHERE user_type IS NULL");

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('user_type')->nullable(false)->change();
            });

            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN user_type ENUM('student', 'staff', 'faculty') NOT NULL
        ");
    }
};
