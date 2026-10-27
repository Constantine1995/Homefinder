<?php

use App\Enums\FlatStatus;
use App\Models\Flat;
use Illuminate\Database\QueryException;

it('stores a 1024-dimensional embedding', function () {
    // Eighths are exact in float4, so the vector survives the round trip unchanged.
    $embedding = array_map(fn (int $i): float => ($i % 8) / 8, range(0, 1023));

    $flat = Flat::factory()->create(['embedding' => $embedding]);

    expect($flat->fresh()->embedding)->toBe($embedding);
});

it('rejects an embedding of another dimension', function () {
    expect(fn () => Flat::factory()->create(['embedding' => [0.5, 0.25]]))
        ->toThrow(QueryException::class, 'expected 1024 dimensions');
});

it('forgets its embedding once its description or features change', function (array $changes) {
    $flat = Flat::factory()->create(['description' => 'Окна во двор.', 'features' => ['window_view' => 'во двор'], 'embedding' => array_fill(0, 1024, 0.5)]);

    $flat->update($changes);

    expect($flat->fresh()->embedding)->toBeNull();
})->with([
    'description' => [['description' => 'Окна на парк.']],
    'features' => [['features' => ['window_view' => 'на улицу']]],
]);

it('keeps its embedding when its price or status change', function () {
    $flat = Flat::factory()->create(['embedding' => array_fill(0, 1024, 0.5)]);

    $flat->update(['price' => 9_100_000, 'status' => FlatStatus::Reserved]);

    expect($flat->fresh()->embedding)->toBe(array_fill(0, 1024, 0.5));
});

it('keeps an embedding given together with the new description', function () {
    $flat = Flat::factory()->create(['description' => 'Окна во двор.']);

    $flat->forceFill(['description' => 'Окна на парк.', 'embedding' => array_fill(0, 1024, 0.25)])->save();

    expect($flat->fresh()->embedding)->toBe(array_fill(0, 1024, 0.25));
});
