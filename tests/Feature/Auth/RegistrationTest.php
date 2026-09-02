<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'clsu_id' => '2021-12345',
        'email' => 'test@clsu.edu.ph',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'test@clsu.edu.ph',
        'clsu_id' => '2021-12345',
    ]);
});

test('registration requires a clsu id', function () {
    $response = $this->from('/register')->post('/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@clsu.edu.ph',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors('clsu_id');
    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'test@clsu.edu.ph']);
});
