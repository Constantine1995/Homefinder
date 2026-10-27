<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the login form to guests', function () {
    $response = $this->get('/login');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

it('logs an employee in and sends them to the catalog', function () {
    $user = User::factory()->create(['email' => 'manager@developer.test']);

    $response = $this->post('/login', ['email' => 'manager@developer.test', 'password' => 'password']);

    $response->assertRedirect('/catalog');
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function () {
    User::factory()->create(['email' => 'manager@developer.test']);

    $response = $this->from('/login')->post('/login', ['email' => 'manager@developer.test', 'password' => 'wrong']);

    $response->assertRedirect('/login')->assertSessionHasErrors(['email' => 'Неверный email или пароль.']);
    $this->assertGuest();
});

it('makes an email wait after five failed attempts, even with the right password', function () {
    User::factory()->create(['email' => 'manager@developer.test']);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'manager@developer.test', 'password' => 'wrong']);
    }
    $response = $this->post('/login', ['email' => 'manager@developer.test', 'password' => 'password']);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toStartWith('Слишком много попыток входа.');
    $this->assertGuest();
});

it('logs the employee out', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post('/logout');

    $response->assertRedirect('/login');
    $this->assertGuest();
});

it('sends a logged-in employee from the login form to the catalog', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->followingRedirects()->get('/login');

    $response->assertInertia(fn (Assert $page) => $page->component('catalog/Index'));
});
