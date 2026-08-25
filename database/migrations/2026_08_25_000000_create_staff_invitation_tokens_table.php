<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Schema is dictated by Illuminate\Auth\Passwords\DatabaseTokenRepository,
     * which inserts exactly ['email', 'token', 'created_at'] and looks rows up
     * by email alone. Adding columns here would break that insert unless they
     * are nullable or defaulted.
     */
    public function up(): void
    {
        Schema::create('staff_invitation_tokens', function (Blueprint $table) {
            // Matches users.email (varchar(150) unique). Primary rather than an
            // index: the repository keeps at most one live token per email.
            $table->string('email', 150)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_invitation_tokens');
    }
};
