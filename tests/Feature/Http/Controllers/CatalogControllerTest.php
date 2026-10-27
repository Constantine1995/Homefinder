<?php

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Enums\PoiType;
use App\Models\Building;
use App\Models\Complex;
use App\Models\Flat;
use App\Models\Poi;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create a developer with two complexes and flats that differ in every filtered field, and log in as its manager.
 *
 * «Отрадный квартал» is 8 minutes from the metro and completed in 2026; «Речная терраса» is 28 minutes away, but has a shop
 * 2 minutes away, and is completed in 2028.
 *
 * @return array{tenant: Tenant, otradny: Complex, river: Complex}
 */
function catalogOfTwoComplexes(): array
{
    $tenant = Tenant::factory()->create();
    $otradny = Complex::factory()->for($tenant)->create(['name' => 'Отрадный квартал']);
    $river = Complex::factory()->for($tenant)->create(['name' => 'Речная терраса']);
    $otradnyBuilding = Building::factory()->for($otradny)->create(['floors' => 17, 'deadline' => '2026-12-31']);
    $riverBuilding = Building::factory()->for($river)->create(['floors' => 24, 'deadline' => '2028-12-31']);
    $otradny->pois()->attach(Poi::factory()->create(['type' => PoiType::Metro, 'name' => 'Владыкино']), ['tenant_id' => $tenant->id, 'distance_m' => 480, 'walk_min' => 8]);
    $otradny->pois()->attach(Poi::factory()->create(['type' => PoiType::Metro, 'name' => 'Отрадное']), ['tenant_id' => $tenant->id, 'distance_m' => 1100, 'walk_min' => 18]);
    $river->pois()->attach(Poi::factory()->create(['type' => PoiType::Metro, 'name' => 'Терехово']), ['tenant_id' => $tenant->id, 'distance_m' => 1685, 'walk_min' => 28]);
    $river->pois()->attach(Poi::factory()->create(['type' => PoiType::Shop, 'name' => 'Пятёрочка']), ['tenant_id' => $tenant->id, 'distance_m' => 90, 'walk_min' => 2]);

    $flats = [
        'studio' => [$otradnyBuilding, ['rooms' => 0, 'is_euro' => false, 'area' => 25, 'price' => 6_500_000, 'floor' => 1, 'finishing' => Finishing::Finished]],
        'one' => [$otradnyBuilding, ['rooms' => 1, 'is_euro' => false, 'area' => 38.5, 'price' => 9_000_000, 'floor' => 5, 'finishing' => Finishing::Rough]],
        'euro-two' => [$otradnyBuilding, ['rooms' => 2, 'is_euro' => true, 'area' => 45, 'price' => 10_500_000, 'floor' => 17, 'finishing' => Finishing::WhiteBox]],
        'three' => [$riverBuilding, ['rooms' => 3, 'is_euro' => false, 'area' => 80, 'price' => 30_000_000, 'floor' => 10, 'finishing' => Finishing::Finished]],
        'five' => [$riverBuilding, ['rooms' => 5, 'is_euro' => false, 'area' => 120, 'price' => 55_000_000, 'floor' => 24, 'finishing' => Finishing::Furnished]],
        'reserved' => [$otradnyBuilding, ['rooms' => 1, 'is_euro' => false, 'area' => 40, 'price' => 9_500_000, 'floor' => 6, 'status' => FlatStatus::Reserved]],
        'sold' => [$riverBuilding, ['rooms' => 2, 'is_euro' => false, 'area' => 60, 'price' => 25_000_000, 'floor' => 3, 'status' => FlatStatus::Sold]],
    ];

    foreach ($flats as $number => [$building, $attributes]) {
        Flat::factory()->for($building)->create(['number' => $number, ...$attributes]);
    }

    test()->actingAs(User::factory()->for($tenant)->create());

    return ['tenant' => $tenant, 'otradny' => $otradny, 'river' => $river];
}

/**
 * @return list<string>
 */
function catalogFlatNumbers(TestResponse $response): array
{
    return collect($response->inertiaProps('flats.data'))->pluck('number')->sort()->values()->all();
}

it('sends guests to the login form', function () {
    $response = $this->get('/catalog');

    $response->assertRedirect('/login');
});

it('shows the flats that match the filters', function (array $filters, array $expectedNumbers) {
    catalogOfTwoComplexes();

    $response = $this->get('/catalog?'.http_build_query($filters));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('catalog/Index'));
    expect(catalogFlatNumbers($response))->toBe(collect($expectedNumbers)->sort()->values()->all());
})->with([
    'no filters: the flats on sale' => [[], ['studio', 'one', 'euro-two', 'three', 'five']],
    'studios and one-room flats' => [['rooms' => [0, 1]], ['studio', 'one']],
    'four rooms and more' => [['rooms' => [4]], ['five']],
    'euro layouts only' => [['euro' => 1], ['euro-two']],
    'no euro layouts' => [['euro' => 0], ['studio', 'one', 'three', 'five']],
    'price range' => [['price_from' => 9_000_000, 'price_to' => 11_000_000], ['one', 'euro-two']],
    'area range' => [['area_from' => 40, 'area_to' => 100], ['euro-two', 'three']],
    'floor range' => [['floor_from' => 5, 'floor_to' => 17], ['one', 'euro-two', 'three']],
    'not the first floor' => [['not_first_floor' => 1], ['one', 'euro-two', 'three', 'five']],
    'not the last floor' => [['not_last_floor' => 1], ['studio', 'one', 'three']],
    'completed by the end of 2026' => [['deadline_to' => '2026-12-31'], ['studio', 'one', 'euro-two']],
    'within 10 minutes of the metro' => [['metro_walk_to' => 10], ['studio', 'one', 'euro-two']],
    'finishings' => [['finishings' => ['finished', 'furnished']], ['studio', 'three', 'five']],
    'reserved and sold flats' => [['statuses' => ['reserved', 'sold']], ['reserved', 'sold']],
]);

it('narrows the catalog down to a complex and shows its surroundings, nearest first', function () {
    ['otradny' => $otradny, 'tenant' => $tenant] = catalogOfTwoComplexes();
    $unnamedPark = Poi::factory()->create(['type' => PoiType::Park, 'name' => null, 'geom' => 'SRID=4326;POINT(37.5931 55.8545)']);
    $otradny->pois()->attach($unnamedPark, ['tenant_id' => $tenant->id, 'distance_m' => 120, 'walk_min' => 2]);

    $response = $this->get("/catalog?complexes[]={$otradny->id}");

    expect(catalogFlatNumbers($response))->toBe(['euro-two', 'one', 'studio']);
    $response->assertInertia(fn (Assert $page) => $page
        ->has('surroundings', 3)
        ->where('surroundings.0.name', 'Парк')
        ->where('surroundings.0.type', 'park')
        ->where('surroundings.0.latitude', 55.8545)
        ->where('surroundings.0.longitude', 37.5931)
        ->where('surroundings.1.name', 'Владыкино')
        ->where('surroundings.1.walk_min', 8)
    );
});

it('shows the complexes with their nearest metro station and what is on sale', function () {
    catalogOfTwoComplexes();

    $response = $this->get('/catalog');

    $response->assertInertia(fn (Assert $page) => $page
        ->has('complexes', 2)
        ->where('complexes.0.name', 'Отрадный квартал')
        ->where('complexes.0.available_count', 3)
        ->where('complexes.0.min_price', 6_500_000)
        ->where('complexes.0.nearest_metro', ['name' => 'Владыкино', 'distance_m' => 480, 'walk_min' => 8])
        ->where('complexes.1.name', 'Речная терраса')
        ->where('complexes.1.available_count', 2)
        ->where('complexes.1.min_price', 30_000_000)
        ->where('surroundings', [])
    );
});

it('sorts the flats and pages through them', function () {
    catalogOfTwoComplexes();

    $response = $this->get('/catalog?sort=price_per_meter&direction=desc');

    expect(collect($response->inertiaProps('flats.data'))->pluck('number')->all())
        ->toBe(['five', 'three', 'studio', 'one', 'euro-two'])
        ->and($response->inertiaProps('flats.data.0.price_per_meter'))->toBe(458_333);
});

it('shows 25 flats a page', function () {
    $building = Building::factory()->create();
    Flat::factory()->count(30)->for($building)->create();
    $this->actingAs(User::factory()->for($building->tenant)->create());

    $response = $this->get('/catalog?page=2');

    $response->assertInertia(fn (Assert $page) => $page
        ->has('flats.data', 5)
        ->where('flats.total', 30)
        ->where('flats.current_page', 2)
    );
});

it('shows the flats and complexes of the employee\'s developer only', function () {
    $otherComplex = Complex::factory()->create();
    Flat::factory()->for(Building::factory()->for($otherComplex))->create(['number' => 'other-developer']);
    catalogOfTwoComplexes();

    $response = $this->get('/catalog');

    expect(catalogFlatNumbers($response))->not->toContain('other-developer');
    expect(collect($response->inertiaProps('complexes'))->pluck('id'))->not->toContain($otherComplex->id);
});

it('does not filter by a complex of another developer', function () {
    catalogOfTwoComplexes();
    $otherComplex = Complex::factory()->create();

    $response = $this->get("/catalog?complexes[]={$otherComplex->id}");

    $response->assertSessionHasErrors('complexes.0');
});

it('rejects filters out of range', function (array $filters, string $field) {
    catalogOfTwoComplexes();

    $response = $this->get('/catalog?'.http_build_query($filters));

    $response->assertSessionHasErrors($field);
})->with([
    'unknown status' => [['statuses' => ['sold-out']], 'statuses.0'],
    'too many rooms' => [['rooms' => [9]], 'rooms.0'],
    'unknown sort' => [['sort' => 'random()'], 'sort'],
    'deadline not a date' => [['deadline_to' => 'IV кв. 2027'], 'deadline_to'],
]);
