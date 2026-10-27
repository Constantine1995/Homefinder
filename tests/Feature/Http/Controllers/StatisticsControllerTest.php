<?php

use App\Enums\UserRole;
use App\Models\Complex;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login form', function () {
    $this->get('/statistics')->assertRedirect('/login');
});

it('shows the sales head the developer\'s statistics for the last 30 days by default', function () {
    $tenant = Tenant::factory()->create();
    Complex::factory()->for($tenant)->create(['name' => 'Тёплые холмы']);
    $conversation = Conversation::factory()->withContact(Contact::factory()->for($tenant)->create())->create(['created_at' => now()->subDays(20)]);
    Message::factory()->for($conversation)->create();
    $old = Conversation::factory()->withContact(Contact::factory()->for($tenant)->create())->create(['created_at' => now()->subDays(40)]);
    Message::factory()->for($old)->create();
    Message::factory()->for(Conversation::factory()->withContact())->create();

    $response = $this->actingAs(User::factory()->for($tenant)->create(['role' => UserRole::SalesHead]))->get('/statistics');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('statistics/Index')
        ->where('days', 30)
        ->where('periods', [7, 30, 90])
        ->where('office_hours', ['opens_at' => '09:00', 'closes_at' => '21:00'])
        ->where('complexes', fn ($complexes): bool => array_values($complexes->all()) === ['Тёплые холмы'])
        ->where('statistics.funnel.conversations', 1)
        ->where('auth.can.view_statistics', true));
});

it('shows a longer period when asked', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->withContact(Contact::factory()->for($tenant)->create())->create(['created_at' => now()->subDays(40)]);
    Message::factory()->for($conversation)->create();

    $response = $this->actingAs(User::factory()->for($tenant)->create(['role' => UserRole::Admin]))->get('/statistics?days=90');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('days', 90)
        ->where('statistics.funnel.conversations', 1)
        ->has('statistics.days', 91));
});

it('rejects a period it does not offer', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/statistics?days=365')
        ->assertSessionHasErrors('days');
});

it('keeps the statistics from managers and the link to them out of their menu', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);

    $this->actingAs($manager)->get('/statistics')->assertForbidden();
    $this->actingAs($manager)->get('/inbox')->assertInertia(fn (Assert $page) => $page->where('auth.can.view_statistics', false));
});
