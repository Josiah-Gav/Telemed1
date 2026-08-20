<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateRequestIds = DB::table('consultations')
            ->select('request_id')
            ->groupBy('request_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_id')
            ->all();

        if (!empty($duplicateRequestIds)) {
            throw new RuntimeException(
                'Cannot add unique index on consultations.request_id. Duplicate request_id values found: '
                . implode(', ', $duplicateRequestIds)
            );
        }

        $duplicateFollowUpRequestIds = DB::table('consultations')
            ->select('follow_up_request_id')
            ->whereNotNull('follow_up_request_id')
            ->groupBy('follow_up_request_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('follow_up_request_id')
            ->all();

        if (!empty($duplicateFollowUpRequestIds)) {
            throw new RuntimeException(
                'Cannot add unique index on consultations.follow_up_request_id. Duplicate follow_up_request_id values found: '
                . implode(', ', $duplicateFollowUpRequestIds)
            );
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->unique('request_id', 'consultations_request_id_unique_ownership');
            $table->unique('follow_up_request_id', 'consultations_follow_up_request_id_unique_ownership');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropUnique('consultations_request_id_unique_ownership');
            $table->dropUnique('consultations_follow_up_request_id_unique_ownership');
        });
    }
};
