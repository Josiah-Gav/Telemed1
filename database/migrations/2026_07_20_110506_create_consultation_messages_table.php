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
        Schema::create('consultation_messages', function (Blueprint $table) {

            $table->id('message_id');

            $table->foreignId('consultation_id')
                ->references('id')
                ->on('consultations')
                ->cascadeOnDelete();

            $table->foreignId('sender_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnDelete();

            $table->text('message')->nullable();

            $table->dateTime('read_at')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_messages', function (Blueprint $table) {
            $table->dropForeign(['consultation_id']);
            $table->dropForeign(['sender_id']);
        });

        Schema::dropIfExists('consultation_messages');
    }
};