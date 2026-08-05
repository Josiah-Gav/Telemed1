<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_slots', function (Blueprint $table) {

            $table->bigIncrements('slot_id');

            $table->unsignedBigInteger('physician_id');

            $table->date('slot_date');
            $table->time('start_time');
            $table->time('end_time');

            $table->enum('status', [
                'available',
                'booked'
            ])->default('available');

            $table->timestamps();

            $table->unique([
                'physician_id',
                'slot_date',
                'start_time'
            ]);

            $table->foreign('physician_id')
                ->references('user_id')
                ->on('users')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->dropForeign(['physician_id']);
        });

        Schema::dropIfExists('schedule_slots');
    }
};