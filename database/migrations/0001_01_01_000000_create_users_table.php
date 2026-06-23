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
    Schema::create('users', function (Blueprint $table) {
        // Match your exact primary key name
        $table->id('user_id'); 
        
        // Split name architecture
        $table->string('first_name', 100);
        $table->string('last_name', 100);
        $table->string('email', 150)->unique();
        $table->timestamp('email_verified_at')->nullable(); // Required for Breeze verification flows
        $table->string('password', 255);
        
        // Exact Enum configurations matching your table (with duplicate values removed)
        $table->enum('role', ['patient', 'nurse', 'physician', 'admin'])->default('patient');
        $table->enum('account_status', ['inactive', 'active', 'suspended'])->default('active');
        $table->enum('online_status', ['offline', 'online'])->default('offline');

        // University / Institutional tracking fields
        $table->string('clsu_id', 50)->nullable();
        $table->enum('user_type', ['student', 'staff', 'faculty']);
        $table->string('department', 100)->nullable();
        
        // Contact and Professional metadata
        $table->string('contact_num', 20)->nullable();
        $table->string('staff_position', 100)->nullable();
        $table->string('specialization', 100)->nullable();
        
        // Session tracking & Timestamps
        $table->timestamp('last_seen_at')->nullable();
        $table->rememberToken(); // Required by Breeze for 'Remember Me' checkboxes
        $table->timestamps();    // Automatically builds your created_at and updated_at
    });

    // Create the 'sessions' table right below it to prevent the previous error
    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->unsignedBigInteger('user_id')->nullable()->index(); // Perfectly matches user_id bigint type
        $table->string('ip_address', 45)->nullable();
        $table->text('user_agent')->nullable();
        $table->longText('payload');
        $table->integer('last_activity')->index();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
