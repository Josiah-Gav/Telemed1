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
        Schema::table('consultation_requests', function (Blueprint $blueprintedTable) {
            // Using text because reasons can be long sentences. 
            // nullable() ensures existing records don't break.
            // after() positions it neatly in your database GUI.
            $blueprintedTable->text('online_reason')
                             ->nullable()
                             ->after('symptoms_desc'); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_requests', function (Blueprint $blueprintedTable) {
            // Always define the rollback rule to drop the column if needed
            $blueprintedTable->dropColumn('online_reason');
        });
    }
};