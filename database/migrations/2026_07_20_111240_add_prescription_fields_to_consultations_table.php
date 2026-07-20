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
        Schema::table('consultations', function (Blueprint $table) {

            $table->string('prescription_file_name')->nullable()->after('recommendations');

            $table->string('prescription_file_path')->nullable()->after('prescription_file_name');

            $table->string('prescription_mime_type', 100)->nullable()->after('prescription_file_path');

            $table->unsignedBigInteger('prescription_file_size')->nullable()->after('prescription_mime_type');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {

            $table->dropColumn([
                'prescription_file_name',
                'prescription_file_path',
                'prescription_mime_type',
                'prescription_file_size'
            ]);

        });
    }
};