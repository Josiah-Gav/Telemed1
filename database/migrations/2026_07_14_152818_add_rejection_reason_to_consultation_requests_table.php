<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRejectionReasonToConsultationRequestsTable extends Migration
{
    public function up()
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            // We make it nullable because pending requests won't have a rejection reason yet!
            $table->text('rejection_reason')->nullable()->after('request_status'); 
        });
    }

    public function down()
    {
        Schema::table('consultation_requests', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
