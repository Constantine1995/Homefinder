<?php

namespace App\Services\Catalog;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Enums\PoiType;
use App\Models\Flat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Hard filters of the flat catalog: what a buyer's «двушка до 9 млн, до метро пешком, сдача не позже 2027»
 * turns into. The agent's `search_flats` is to filter by the same fields.
 */
readonly class FlatFilters
{
    /**
     * The room count that stands for four rooms and more.
     */
    public const int MANY_ROOMS = 4;

    /**
     * @param  list<int>  $complexIds
     * @param  list<int>  $rooms  Room counts: 0 for studios and free layouts, MANY_ROOMS for four rooms and more.
     * @param  bool|null  $isEuro  Only euro layouts, only others, or both when null.
     * @param  string|null  $deadlineTo  The latest completion date, as Y-m-d; buildings without a date are left out.
     * @param  int|null  $metroWalkTo  The longest walk to the nearest metro station, in minutes.
     * @param  list<Finishing>  $finishings
     * @param  list<FlatStatus>  $statuses
     * @param  list<PoiType>  $nearby  Kinds of places the complex must have near: «рядом школа».
     */
    public function __construct(
        public array $complexIds = [],
        public array $rooms = [],
        public ?bool $isEuro = null,
        public ?int $priceFrom = null,
        public ?int $priceTo = null,
        public ?float $areaFrom = null,
        public ?float $areaTo = null,
        public ?int $floorFrom = null,
        public ?int $floorTo = null,
        public bool $notFirstFloor = false,
        public bool $notLastFloor = false,
        public ?string $deadlineTo = null,
        public ?int $metroWalkTo = null,
        public array $finishings = [],
        public array $statuses = [FlatStatus::Available],
        public array $nearby = [],
    ) {}

    /**
     * Read the agent's search conditions (agent/src/homefinder/search/filters.py), as the dialog state keeps them.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromAgent(array $filters): self
    {
        return new self(
            complexIds: $filters['complex_ids'] ?? [],
            rooms: $filters['rooms'] ?? [],
            isEuro: $filters['is_euro'] ?? null,
            priceFrom: $filters['price_from'] ?? null,
            priceTo: $filters['price_to'] ?? null,
            areaFrom: $filters['area_from'] ?? null,
            areaTo: $filters['area_to'] ?? null,
            floorFrom: $filters['floor_from'] ?? null,
            floorTo: $filters['floor_to'] ?? null,
            notFirstFloor: $filters['not_first_floor'] ?? false,
            notLastFloor: $filters['not_last_floor'] ?? false,
            deadlineTo: $filters['deadline_to'] ?? null,
            metroWalkTo: $filters['metro_walk_to'] ?? null,
            finishings: array_map(Finishing::from(...), $filters['finishings'] ?? []),
            statuses: array_map(FlatStatus::from(...), $filters['statuses'] ?? [FlatStatus::Available->value]),
            nearby: array_map(PoiType::from(...), $filters['nearby'] ?? []),
        );
    }

    /**
     * Filter the flats of a query that is joined with their buildings.
     *
     * @param  Builder<Flat>  $query
     * @return Builder<Flat>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->complexIds !== [], fn (Builder $query) => $query->whereIn('buildings.complex_id', $this->complexIds))
            ->when($this->rooms !== [], fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->whereIn('flats.rooms', array_values(array_diff($this->rooms, [self::MANY_ROOMS])));

                if (in_array(self::MANY_ROOMS, $this->rooms, true)) {
                    $query->orWhere('flats.rooms', '>=', self::MANY_ROOMS);
                }
            }))
            ->when($this->isEuro !== null, fn (Builder $query) => $query->where('flats.is_euro', $this->isEuro))
            ->when($this->priceFrom !== null, fn (Builder $query) => $query->where('flats.price', '>=', $this->priceFrom))
            ->when($this->priceTo !== null, fn (Builder $query) => $query->where('flats.price', '<=', $this->priceTo))
            ->when($this->areaFrom !== null, fn (Builder $query) => $query->where('flats.area', '>=', $this->areaFrom))
            ->when($this->areaTo !== null, fn (Builder $query) => $query->where('flats.area', '<=', $this->areaTo))
            ->when($this->floorFrom !== null, fn (Builder $query) => $query->where('flats.floor', '>=', $this->floorFrom))
            ->when($this->floorTo !== null, fn (Builder $query) => $query->where('flats.floor', '<=', $this->floorTo))
            ->when($this->notFirstFloor, fn (Builder $query) => $query->where('flats.floor', '>', 1))
            ->when($this->notLastFloor, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNull('buildings.floors')->orWhereColumn('flats.floor', '<', 'buildings.floors'),
            ))
            ->when($this->deadlineTo !== null, fn (Builder $query) => $query->where('buildings.deadline', '<=', $this->deadlineTo))
            ->when($this->metroWalkTo !== null, fn (Builder $query) => $query->whereIn('buildings.complex_id', $this->complexesNearMetro()))
            ->when($this->finishings !== [], fn (Builder $query) => $query->whereIn('flats.finishing', $this->finishings))
            ->when($this->nearby !== [], function (Builder $query): void {
                foreach ($this->nearby as $type) {
                    $query->whereIn('buildings.complex_id', $this->complexesNear($type));
                }
            })
            ->when($this->statuses !== [], fn (Builder $query) => $query->whereIn('flats.status', $this->statuses));
    }

    /**
     * Get the complexes with a place of the kind near: the geo import keeps such places within a kilometer.
     */
    private function complexesNear(PoiType $type): QueryBuilder
    {
        return DB::table('complex_poi')
            ->join('pois', 'pois.id', '=', 'complex_poi.poi_id')
            ->where('pois.type', $type)
            ->select('complex_poi.complex_id');
    }

    /**
     * Get the complexes within the walk of a metro station.
     */
    private function complexesNearMetro(): QueryBuilder
    {
        return DB::table('complex_poi')
            ->join('pois', 'pois.id', '=', 'complex_poi.poi_id')
            ->where('pois.type', PoiType::Metro)
            ->where('complex_poi.walk_min', '<=', $this->metroWalkTo)
            ->select('complex_poi.complex_id');
    }
}
