<?php

use App\Models\Complex;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * What the agent service answers to a search by a message that finds one flat.
 *
 * @return array<string, mixed>
 */
function agentQueryAnswer(): array
{
    return [
        'query' => ['filters' => ['rooms' => [2], 'price_to' => 9_000_000], 'wishes' => 'окна во двор', 'unknown_complexes' => []],
        'result' => ['total' => 1, 'flats' => [['id' => 7, 'price' => 8_900_000, 'reasons' => ['2 комнаты']]]],
        'relaxations' => [],
    ];
}

it('sends guests to the login form', function () {
    $this->get('/search?'.http_build_query(['q' => 'двушка']))->assertRedirect('/login');
});

it('offers examples and asks the agent service nothing without a message', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/search');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('search/Index')
        ->where('text', '')
        ->where('search', null)
        ->has('examples', 5));
    Http::assertNothingSent();
});

it('searches by the message for the tenant of the employee, whatever the request says', function () {
    $tenant = Tenant::factory()->create();
    $complex = Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);
    Complex::factory()->create(['name' => 'Чужой ЖК']);
    $this->actingAs(User::factory()->for($tenant)->create());
    Http::fake(['agent.invalid/v1/flats/query' => Http::response(agentQueryAnswer())]);

    $response = $this->get('/search?'.http_build_query(['q' => ' двушка до 9 млн, окна во двор ', 'tenant_id' => 999]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('text', 'двушка до 9 млн, окна во двор')
        ->where('search.result.total', 1)
        ->where('search.query.wishes', 'окна во двор')
        ->where('complexes', [$complex->id => 'Отрадный квартал']));
    Http::assertSent(fn (Request $request): bool => $request->data() === ['tenant_id' => $tenant->id, 'text' => 'двушка до 9 млн, окна во двор']);
});

it('searches by the eased filters the employee chose, with the same wishes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::fake(['agent.invalid/v1/flats/search' => Http::response(['total' => 12, 'flats' => []])]);

    $response = $this->get('/search?'.http_build_query([
        'q' => 'двушка до 9 млн',
        'filters' => json_encode(['rooms' => [2], 'price_to' => 9_500_000]),
        'wishes' => 'окна во двор',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('search.result.total', 12)
        ->where('search.query.filters.price_to', 9_500_000)
        ->where('search.relaxations', []));
    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'tenant_id' => $user->tenant_id,
        'filters' => ['rooms' => [2], 'price_to' => 9_500_000],
        'wishes' => 'окна во двор',
    ]);
});

it('says the search is unavailable when the agent service is down', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['agent.invalid/v1/flats/query' => Http::response(['detail' => 'The language model is unavailable'], 503)]);

    $response = $this->get('/search?'.http_build_query(['q' => 'двушка']));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('search.error', 'Поиск по тексту сейчас недоступен: сервис ИИ не отвечает. Каталог с фильтрами работает.'));
});

it('rejects eased filters that are not JSON', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get('/search?'.http_build_query(['q' => 'двушка', 'filters' => 'rooms']));

    $response->assertSessionHasErrors(['filters' => 'The filters field must be a valid JSON string.']);
    Http::assertNothingSent();
});
