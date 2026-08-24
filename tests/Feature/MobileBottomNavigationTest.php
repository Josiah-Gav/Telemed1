<?php

use App\Models\User;

test('physician mobile bottom nav links to physician routes, not the patient-only ones', function () {
    $physician = User::factory()->create(['role' => 'physician']);

    $response = $this->actingAs($physician)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('physician.consultation_inbox', ['physician' => $physician]), false);
    $response->assertSee(route('physician.consultation_history', ['physician' => $physician]), false);
    $response->assertDontSee(route('newconsultation'), false);
    $response->assertDontSee(route('consultations.history'), false);
});

test('patient mobile bottom nav is unchanged', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $response = $this->actingAs($patient)->get('/dashboard');

    $response->assertOk();
    $response->assertSee(route('newconsultation'), false);
    $response->assertSee(route('consultations.history'), false);
});

test('nurse mobile bottom nav is unchanged', function () {
    $nurse = User::factory()->create(['role' => 'nurse']);

    $response = $this->actingAs($nurse)->get(route('nurse.dashboard', ['nurse' => $nurse]));

    $response->assertOk();
    $response->assertSee(route('nurse.consultation_inbox', ['nurse' => $nurse]), false);
    $response->assertSee(route('nurse.consultation_history', ['nurse' => $nurse]), false);
});
