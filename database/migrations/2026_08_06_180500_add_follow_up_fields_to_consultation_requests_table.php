<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->enum('type', ['initial', 'follow_up'])
                ->default('initial')
                ->after('assigned_nurse_id');

            $table->foreignId('parent_consultation_id')
                ->nullable()
                ->after('type')
                ->constrained('consultations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->dropForeign(['parent_consultation_id']);
            $table->dropColumn(['type', 'parent_consultation_id']);
        });
    }
};