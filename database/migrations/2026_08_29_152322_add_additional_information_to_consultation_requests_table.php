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
        Schema::table('consultation_requests', function (Blueprint $table) {
            // Patient's optional free-text elaboration submitted alongside symptoms
            // (newconsultation.blade.php's "additional_notes" textarea) — the form
            // has always sent it, but nothing stored it until now.
            $table->text('additional_information')->nullable()->after('online_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->dropColumn('additional_information');
        });
    }
};
