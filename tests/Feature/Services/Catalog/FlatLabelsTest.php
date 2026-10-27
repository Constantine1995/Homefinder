<?php

use App\Models\Flat;
use App\Services\Catalog\FlatLabels;

it('asks for flats like the one in the words the agent understands, up to a tenth dearer', function (array $flat, string $request) {
    expect(FlatLabels::similarRequest(Flat::factory()->make($flat)))->toBe($request);
})->with([
    'a studio' => [['rooms' => 0, 'is_euro' => false, 'price' => 6_000_000, 'features' => []], 'студия до 6,6 млн ₽'],
    'a euro layout' => [['rooms' => 2, 'is_euro' => true, 'price' => 8_459_000, 'features' => []], 'евродвушка до 9,4 млн ₽'],
    'three rooms' => [['rooms' => 3, 'is_euro' => false, 'price' => 14_000_000, 'features' => []], 'трёшка до 15,4 млн ₽'],
    'five rooms' => [['rooms' => 5, 'is_euro' => false, 'price' => 30_000_000, 'features' => []], '5-комнатная до 33 млн ₽'],
    'an open plan' => [['rooms' => 1, 'is_euro' => false, 'price' => 9_000_000, 'features' => ['open_plan' => true]], 'свободная планировка до 9,9 млн ₽'],
]);
