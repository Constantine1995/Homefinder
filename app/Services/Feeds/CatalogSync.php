<?php

namespace App\Services\Feeds;

use App\Enums\FlatEventField;
use App\Enums\FlatStatus;
use App\Models\Building;
use App\Models\Complex;
use App\Models\FeedImport;
use App\Models\FeedSource;
use App\Models\Flat;
use App\Models\FlatEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Brings a feed source's part of the catalog in line with one copy of its feed: creates and updates
 * complexes, buildings and flats, withdraws the flats that left the feed and records every price
 * and status change in the flats' history.
 *
 * Expects the source's tenant to be current and a transaction to be open, so that a feed found
 * broken halfway leaves the catalog as it was.
 */
class CatalogSync
{
    /**
     * Number of offers whose flats are loaded and saved together.
     */
    private const int BATCH_SIZE = 500;

    /**
     * Rejected offers kept in the report; a broken feed may reject all of its thousands of offers.
     */
    private const int MAX_ROW_ERRORS = 100;

    /**
     * @var array<string, Complex>
     */
    private array $complexes = [];

    /**
     * @var array<string, array<string, Building>>
     */
    private array $buildings = [];

    /**
     * External IDs of the flats listed in the feed, as keys.
     *
     * @var array<string, true>
     */
    private array $listedFlatIds = [];

    /**
     * History records waiting to be inserted together.
     *
     * @var list<array<string, mixed>>
     */
    private array $events = [];

    private int $addedCount = 0;

    private int $updatedCount = 0;

    private int $removedCount = 0;

    private int $failedCount = 0;

    /**
     * @var list<array{row: int, external_id: ?string, message: string}>
     */
    private array $rowErrors = [];

    public function __construct(
        private FeedSource $source,
        private FeedImport $import,
    ) {}

    /**
     * Apply the feed's offers to the catalog and get the import report.
     *
     * @param  iterable<int, FeedOffer|InvalidOffer>  $offers  Offers keyed by their position in the feed.
     * @return array{added_count: int, updated_count: int, removed_count: int, failed_count: int, row_errors: list<array{row: int, external_id: ?string, message: string}>}
     *
     * @throws FeedException when the feed has offers but none of them is valid
     */
    public function sync(iterable $offers): array
    {
        $batch = [];

        foreach ($offers as $row => $offer) {
            if ($offer instanceof InvalidOffer) {
                $this->reject($row, $offer->externalId, $offer->reason);

                continue;
            }

            $flatId = $offer->flat['external_id'];

            if (isset($this->listedFlatIds[$flatId])) {
                $this->reject($row, $flatId, 'An earlier offer has the same flat ID.');

                continue;
            }

            $this->listedFlatIds[$flatId] = true;
            $batch[] = $offer;

            if (count($batch) === self::BATCH_SIZE) {
                $this->saveFlats($batch);
                $batch = [];
            }
        }

        $this->saveFlats($batch);

        // A feed broken throughout, say by a new export template, must not empty the catalog.
        if ($this->listedFlatIds === [] && $this->failedCount > 0) {
            throw new FeedException("None of the feed's {$this->failedCount} offers is valid. The first one was rejected: {$this->rowErrors[0]['message']}");
        }

        $this->withdrawUnlistedFlats();
        $this->placeComplexes();

        return [
            'added_count' => $this->addedCount,
            'updated_count' => $this->updatedCount,
            'removed_count' => $this->removedCount,
            'failed_count' => $this->failedCount,
            'row_errors' => $this->rowErrors,
        ];
    }

    /**
     * @param  list<FeedOffer>  $offers
     */
    private function saveFlats(array $offers): void
    {
        if ($offers === []) {
            return;
        }

        $flats = $this->source->flats()
            ->whereIn('external_id', array_map(fn (FeedOffer $offer): string => $offer->flat['external_id'], $offers))
            ->get()
            ->keyBy('external_id');

        foreach ($offers as $offer) {
            $this->saveFlat($flats->get($offer->flat['external_id']) ?? new Flat, $offer);
        }

        $this->insertEvents();
    }

    private function saveFlat(Flat $flat, FeedOffer $offer): void
    {
        $attributes = $offer->flat;

        // Postgres reorders the keys of jsonb, so stored features never strictly equal the feed's ones.
        if ($flat->exists && $flat->features == $attributes['features']) {
            unset($attributes['features']);
        }

        $flat->fill($attributes);
        $flat->building()->associate($this->building($offer));

        if (! $flat->exists) {
            $flat->feedSource()->associate($this->source);
            $flat->save();
            $this->addedCount++;
            $this->recordChange($flat, FlatEventField::Status, null, $flat->status->value);
            $this->recordChange($flat, FlatEventField::Price, null, $flat->price);

            return;
        }

        if (! $flat->isDirty()) {
            return;
        }

        if ($flat->isDirty('status')) {
            $this->recordChange($flat, FlatEventField::Status, $flat->getOriginal('status')->value, $flat->status->value);
        }

        if ($flat->isDirty('price')) {
            $this->recordChange($flat, FlatEventField::Price, $flat->getOriginal('price'), $flat->price);
        }

        $flat->save();
        $this->updatedCount++;
    }

    private function building(FeedOffer $offer): Building
    {
        $complex = $this->complex($offer);
        $buildingId = $offer->building['external_id'];

        if (isset($this->buildings[$complex->external_id][$buildingId])) {
            return $this->buildings[$complex->external_id][$buildingId];
        }

        $building = $complex->buildings()->firstOrNew(['external_id' => $buildingId]);
        $attributes = $offer->building;

        // A source without coordinates must not erase the ones the geocoder found for the same address.
        if ($attributes['latitude'] === null && $attributes['longitude'] === null && $building->address === $attributes['address']) {
            unset($attributes['latitude'], $attributes['longitude']);
        }

        return $this->buildings[$complex->external_id][$buildingId] = $this->saved($building, $attributes);
    }

    private function complex(FeedOffer $offer): Complex
    {
        $complexId = $offer->complex['external_id'];

        return $this->complexes[$complexId] ??= $this->saved(
            Complex::firstOrNew(['external_id' => $complexId]),
            $offer->complex,
        );
    }

    /**
     * Fill the model with the attributes and save it if anything changed.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function saved(Model $model, array $attributes): Model
    {
        $model->fill($attributes)->save();

        return $model;
    }

    /**
     * Withdraw the source's flats the feed no longer lists; they stay in the catalog with their history.
     */
    private function withdrawUnlistedFlats(): void
    {
        $unlistedFlats = $this->source->flats()
            ->where('status', '!=', FlatStatus::Withdrawn)
            ->get(['id', 'tenant_id', 'external_id', 'status'])
            ->reject(fn (Flat $flat): bool => isset($this->listedFlatIds[$flat->external_id]));

        foreach ($unlistedFlats->chunk(self::BATCH_SIZE) as $flats) {
            Flat::whereKey($flats->modelKeys())->update(['status' => FlatStatus::Withdrawn]);

            foreach ($flats as $flat) {
                $this->recordChange($flat, FlatEventField::Status, $flat->status->value, FlatStatus::Withdrawn->value);
            }

            $this->insertEvents();
        }

        $this->removedCount = $unlistedFlats->count();
    }

    private function placeComplexes(): void
    {
        foreach ($this->complexes as $complex) {
            $complex->placeAtBuildings();
        }
    }

    private function recordChange(Flat $flat, FlatEventField $field, int|string|null $oldValue, int|string $newValue): void
    {
        $this->events[] = [
            'tenant_id' => $flat->tenant_id,
            'flat_id' => $flat->id,
            'feed_import_id' => $this->import->id,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ];
    }

    private function insertEvents(): void
    {
        if ($this->events !== []) {
            FlatEvent::fillAndInsert($this->events);
            $this->events = [];
        }
    }

    private function reject(int $row, ?string $externalId, string $reason): void
    {
        $this->failedCount++;

        if (count($this->rowErrors) < self::MAX_ROW_ERRORS) {
            $this->rowErrors[] = ['row' => $row, 'external_id' => $externalId, 'message' => $reason];
        }
    }
}
