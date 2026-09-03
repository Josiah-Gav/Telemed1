<?php

use App\Models\User;

/**
 * Coverage for the "no past slots" restriction added to
 * PhysicianController::generateScheduleSlots() (the preview) and
 * saveScheduleSlots() (the actual insert). slot_date itself is already
 * validated after_or_equal:today by the form requests, so the only gap
 * this closes is a same-day time that has already elapsed by the moment
 * the request is made — time is frozen with travelTo() so "elapsed" is
 * deterministic rather than racing the real clock.
 */
const SLOT_TEST_DATE = '2030-06-10';

function slotTestPhysician(array $overrides = []): User
{
    return User::factory()->create(array_merge(['role' => 'physician', 'user_type' => 'staff'], $overrides));
}

it('excludes already-elapsed slots from the generated preview for today, but keeps the rest', function () {
    $physician = slotTestPhysician();
    $this->travelTo(SLOT_TEST_DATE.' 12:30:00');

    $response = $this->actingAs($physician)->postJson(
        route('physician.scheduled_consultation.generate', ['physician' => $physician->user_id]),
        [
            'slot_date' => SLOT_TEST_DATE,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'duration_minutes' => 60,
        ]
    );

    $response->assertOk();
    // 08:00, 09:00, 10:00, 11:00, 12:00 have already started by 12:30; 13:00..16:00 remain.
    expect($response->json('summary.skipped_by_past'))->toBe(5);
    expect($response->json('summary.generated_count'))->toBe(4);
    $labels = collect($response->json('slots'))->pluck('start_time');
    expect($labels->contains('08:00:00'))->toBeFalse();
    expect($labels->contains('13:00:00'))->toBeTrue();
});

it('does not skip any slot by past time when generating for a future date', function () {
    $physician = slotTestPhysician();
    $this->travelTo(SLOT_TEST_DATE.' 12:30:00');

    $response = $this->actingAs($physician)->postJson(
        route('physician.scheduled_consultation.generate', ['physician' => $physician->user_id]),
        [
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'duration_minutes' => 60,
        ]
    );

    $response->assertOk();
    expect($response->json('summary.skipped_by_past'))->toBe(0);
    expect($response->json('summary.generated_count'))->toBe(2);
});

it('rejects an already-elapsed slot at save time instead of inserting it', function () {
    $physician = slotTestPhysician();
    $this->travelTo(SLOT_TEST_DATE.' 12:30:00');

    $response = $this->actingAs($physician)->postJson(
        route('physician.scheduled_consultation.save', ['physician' => $physician->user_id]),
        [
            'slot_date' => SLOT_TEST_DATE,
            'slots' => [
                ['start_time' => '08:00:00', 'end_time' => '09:00:00'],
            ],
        ]
    );

    $response->assertOk();
    expect($response->json('summary.saved_count'))->toBe(0);
    expect($response->json('summary.skipped_by_past'))->toBe(1);
    $this->assertDatabaseMissing('schedule_slots', ['physician_id' => $physician->user_id]);
});

it('still saves a slot later the same day while skipping an elapsed one in the same request', function () {
    $physician = slotTestPhysician();
    $this->travelTo(SLOT_TEST_DATE.' 12:30:00');

    $response = $this->actingAs($physician)->postJson(
        route('physician.scheduled_consultation.save', ['physician' => $physician->user_id]),
        [
            'slot_date' => SLOT_TEST_DATE,
            'slots' => [
                ['start_time' => '08:00:00', 'end_time' => '09:00:00'],
                ['start_time' => '14:00:00', 'end_time' => '15:00:00'],
            ],
        ]
    );

    $response->assertOk();
    expect($response->json('summary.saved_count'))->toBe(1);
    expect($response->json('summary.skipped_by_past'))->toBe(1);
    $this->assertDatabaseHas('schedule_slots', [
        'physician_id' => $physician->user_id,
        'start_time' => '14:00:00',
    ]);
    $this->assertDatabaseMissing('schedule_slots', [
        'physician_id' => $physician->user_id,
        'start_time' => '08:00:00',
    ]);
});

it('still saves a future-dated slot normally, unaffected by the past-time check', function () {
    $physician = slotTestPhysician();
    $this->travelTo(SLOT_TEST_DATE.' 12:30:00');
    $futureDate = now()->addDay()->toDateString();

    $response = $this->actingAs($physician)->postJson(
        route('physician.scheduled_consultation.save', ['physician' => $physician->user_id]),
        [
            'slot_date' => $futureDate,
            'slots' => [
                ['start_time' => '08:00:00', 'end_time' => '09:00:00'],
            ],
        ]
    );

    $response->assertOk();
    expect($response->json('summary.saved_count'))->toBe(1);
    expect($response->json('summary.skipped_by_past'))->toBe(0);
});
