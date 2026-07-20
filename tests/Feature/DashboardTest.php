<?php

use App\Models\User;

test('admin users can access the dashboard', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($admin)->get('/dashboard');

    $response->assertStatus(200);
    $response->assertSee('Admin Dashboard');
});
