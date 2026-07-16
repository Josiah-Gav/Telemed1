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
            // We set 'Normal' as the default value so all your existing records 
            // automatically get populated with 'Normal' instead of causing a database failure.
            $table->enum('priority_level', ['High', 'Normal'])
                  ->default('Normal')
                  ->after('request_status'); // Positions it right after your status column
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->dropColumn('priority_level');
        });
    }
};