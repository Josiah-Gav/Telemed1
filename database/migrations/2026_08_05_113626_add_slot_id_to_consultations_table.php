<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {

            $table->unsignedBigInteger('slot_id')
                ->nullable()
                ->after('physician_id');

            $table->foreign('slot_id')
                ->references('slot_id')
                ->on('schedule_slots')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {

            $table->dropForeign(['slot_id']);
            $table->dropColumn('slot_id');

        });
    }
};