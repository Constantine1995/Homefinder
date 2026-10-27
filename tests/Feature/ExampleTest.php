<?php

use App\Http\Controllers\SearchController;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the application greets guests with the product page', function () {
    $response = $this->get('/');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('home/Index')
        ->where('example', SearchController::EXAMPLES[0])
        ->where('auth.user', null));
});

test('an employee sees the product page too, with the way into the cabinet', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('home/Index')
        ->where('auth.user.id', $user->id));
});
