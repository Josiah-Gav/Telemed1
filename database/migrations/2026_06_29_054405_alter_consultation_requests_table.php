<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            // Drop the old column
            $table->dropColumn('preffered_consultation_type');
            
            // Add a new column for files (longText or json works great here to store an array of links)
            $table->longText('file_attachments')->nullable()->after('symptoms_desc');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->enum('preffered_consultation_type', ['video', 'chat', 'audio'])->after('request_status');
            $table->dropColumn('file_attachments');
        });
    }
};