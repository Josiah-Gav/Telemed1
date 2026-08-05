<?php

namespace App\Console\Commands;

use App\Models\ConsultationSession;
use App\Models\ScheduleSlot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkMissedScheduleSlots extends Command
{
    protected $signature = 'consultations:mark-missed-slots';

    protected $description = 'Mark scheduled consultation slots as missed once slot end time has passed without consultation start.';

    public function handle(): int
    {
        $candidateSessionIds = ConsultationSession::query()
            ->where('consultation_status', 'scheduled')
            ->whereNotNull('slot_id')
            ->whereHas('request', function ($query) {
                $query->where('request_status', 'scheduled');
            })
            ->whereHas('slot', function ($query) {
                $query->where('status', 'booked');
            })
            ->pluck('id');

        if ($candidateSessionIds->isEmpty()) {
            $this->info('No scheduled booked slots to evaluate.');
            return self::SUCCESS;
        }

        $markedAsMissedCount = 0;

        foreach ($candidateSessionIds as $sessionId) {
            DB::transaction(function () use ($sessionId, &$markedAsMissedCount) {
                $session = ConsultationSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->first();

                if (!$session || $session->consultation_status !== 'scheduled' || !$session->slot_id) {
                    return;
                }

                $request = $session->request()->lockForUpdate()->first();
                if (!$request || $request->request_status !== 'scheduled') {
                    return;
                }

                $slot = ScheduleSlot::query()
                    ->where('slot_id', $session->slot_id)
                    ->lockForUpdate()
                    ->first();

                if (!$slot || $slot->status !== 'booked') {
                    return;
                }

                $slotDate = $slot->slot_date?->format('Y-m-d') ?? (string) $slot->slot_date;
                $slotEndsAt = CarbonImmutable::parse($slotDate . ' ' . $slot->end_time);

                if (CarbonImmutable::now()->lessThanOrEqualTo($slotEndsAt)) {
                    return;
                }

                $slot->update([
                    'status' => 'missed',
                ]);

                $markedAsMissedCount++;
            });
        }

        $this->info('Marked ' . $markedAsMissedCount . ' slot(s) as missed.');

        return self::SUCCESS;
    }
}
