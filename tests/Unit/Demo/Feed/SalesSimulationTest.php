<?php

use App\Demo\Feed\DemoFlat;
use App\Demo\Feed\DemoWorld;
use App\Demo\Feed\SalesSimulation;
use App\Demo\Feed\SaleStatus;

it('keeps the flats of a building off sale until its sales start', function () {
    $world = new DemoWorld;
    $simulation = new SalesSimulation($world);
    $flatsOfNewBuilding = array_filter(
        $world->flats(),
        fn (DemoFlat $flat): bool => $flat->complex->code === 'TH' && $flat->building->number === 3,
    );
    $statusOn = fn (int $day): array => array_map(
        fn (DemoFlat $flat): SaleStatus => $simulation->stateOf($flat, $day)->status,
        $flatsOfNewBuilding,
    );

    $statusesBeforeSalesStart = $statusOn(6);
    $statusesOnSalesStart = $statusOn(7);

    expect($flatsOfNewBuilding)->toHaveCount(110)
        ->and($statusesBeforeSalesStart)->each->toBe(SaleStatus::Upcoming)
        ->and($statusesOnSalesStart)->each->toBe(SaleStatus::Available);
});

it('never puts a sold flat back on sale', function () {
    $world = new DemoWorld;
    $simulation = new SalesSimulation($world);
    $flatsBackOnSaleAfterSale = [];

    foreach ($world->flats() as $flat) {
        $isSold = false;

        foreach (range(0, 60) as $day) {
            $status = $simulation->stateOf($flat, $day)->status;

            if ($isSold && $status !== SaleStatus::Sold) {
                $flatsBackOnSaleAfterSale[] = "{$flat->id()} on day {$day}";
            }

            $isSold = $isSold || $status === SaleStatus::Sold;
        }
    }

    expect($flatsBackOnSaleAfterSale)->toBe([]);
});

it('releases, reserves, sells, returns and discounts flats within a week', function () {
    $simulation = new SalesSimulation(new DemoWorld);

    $changes = $simulation->changesBetween(0, 7);

    expect($changes)
        ->released->toBeGreaterThan(0)
        ->reserved->toBeGreaterThan(0)
        ->sold->toBeGreaterThan(0)
        ->returned->toBeGreaterThan(0)
        ->cheaper->toBeGreaterThan(0);
});

it('raises the prices of buildings under construction once a month', function () {
    $simulation = new SalesSimulation(new DemoWorld);

    $changesBeforeRise = $simulation->changesBetween(28, 29);
    $changesOnRise = $simulation->changesBetween(29, 30);

    expect($changesBeforeRise->pricier)->toBe(0)
        ->and($changesOnRise->pricier)->toBeGreaterThan(0);
});
