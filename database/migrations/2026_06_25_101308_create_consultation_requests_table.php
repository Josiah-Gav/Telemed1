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
        Schema::create('consultation_requests', function (Blueprint $table) {
            // PK -> request_id : BIGINT
            $table->id('request_id'); 
            
            // FK -> patient_id : BIGINT (links to users.user_id)
            $table->foreignId('patient_id')->constrained('users', 'user_id')->onDelete('cascade');
            
            // FK -> assigned_physician_id : BIGINT NULL (links to users.user_id)
            $table->foreignId('assigned_physician_id')->nullable()->constrained('users', 'user_id')->onDelete('set null');
            
            // concern_category : VARCHAR(100)
            $table->string('concern_category', 100);
            
            // symptoms_desc : TEXT
            $table->text('symptoms_desc');
            
            // request_status : ENUM('pending', 'reviewed', 'assigned', 'scheduled', 'active', 'completed', 'rejected', 'cancelled')
            $table->enum('request_status', [
                'pending', 
                'reviewed', 
                'assigned', 
                'scheduled', 
                'active', 
                'completed', 
                'rejected', 
                'cancelled'
            ])->default('pending');
            
            // preffered_consultation_type : ENUM (e.g., 'video', 'chat', 'in_person' depending on your design)
            $table->enum('preffered_consultation_type', ['video', 'chat', 'audio']); 
            
            // submitted_at : TIMESTAMP & updated_at : TIMESTAMP
            // Using Laravel's built-in tracker or explicit timestamps matching your diagram
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // FK -> assigned_nurse_id : BIGINT (links to users.user_id)
            $table->foreignId('assigned_nurse_id')->nullable()->constrained('users', 'user_id')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consultation_requests');
    }
};