<?php

use App\Demo\Feed\DemoWorld;
use App\Demo\Feed\Layout;

// The Yandex format has no euro-layout flag, so the importer tells euro layouts apart by the kitchen area.
it('gives euro layouts a kitchen-living room of at least 15 m²', function (Layout $layout) {
    expect($layout->kitchenSpace())->toBeGreaterThanOrEqual(15.0);
})->with(fn (): array => array_filter((new DemoWorld)->layouts(), fn (Layout $layout): bool => $layout->isEuro()));

it('gives classic layouts a kitchen smaller than 15 m²', function (Layout $layout) {
    expect($layout->kitchenSpace())->toBeLessThan(15.0);
})->with(fn (): array => array_filter(
    (new DemoWorld)->layouts(),
    fn (Layout $layout): bool => ! $layout->isEuro() && ! $layout->isStudio(),
));

it('fits the service rooms of every layout under its rooms with windows', function (Layout $layout) {
    $facadeWidth = array_sum(array_column($layout->facadeRooms, 1));
    $innerWidth = array_sum(array_column($layout->innerRooms, 1));

    expect($innerWidth)->toEqualWithDelta($facadeWidth, 0.001);
})->with(fn (): array => (new DemoWorld)->layouts());
