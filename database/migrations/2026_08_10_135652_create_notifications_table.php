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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notification_id');

            // User who will receive the notification
            $table->unsignedBigInteger('user_id');

            // Notification classification
            $table->string('type');

            // Notification content
            $table->string('title');
            $table->text('message');

            // Optional data related to the notification
            // Example: consultation_id, follow_up_request_id, etc.
            $table->json('data')->nullable();

            // NULL = unread, timestamp = read
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // Foreign key
            $table->foreign('user_id')
                ->references('user_id')
                ->on('users')
                ->onDelete('cascade');

            // Useful for retrieving a user's notifications
            $table->index(['user_id', 'read_at']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};