<?php

namespace App\Services\Catalog;

use App\Enums\FlatStatus;
use App\Enums\PoiType;
use App\Models\Building;
use App\Models\Complex;
use App\Models\Flat;
use App\Models\Poi;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Reads the current tenant's catalog for the sales cabinet: flats by filters, complexes for the map
 * and the surroundings of a complex.
 */
class CatalogQuery
{
    public const int PER_PAGE = 25;

    /**
     * Columns the flats can be sorted by, by sort name.
     *
     * @var array<string, string>
     */
    public const array SORTS = [
        'price' => 'flats.price',
        'price_per_meter' => 'price_per_meter',
        'area' => 'flats.area',
        'floor' => 'flats.floor',
        'deadline' => 'buildings.deadline',
    ];

    /**
     * @param  'asc'|'desc'  $direction
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function flats(FlatFilters $filters, string $sort, string $direction): LengthAwarePaginator
    {
        $query = Flat::query()
            ->join('buildings', 'buildings.id', '=', 'flats.building_id')
            ->join('complexes', 'complexes.id', '=', 'buildings.complex_id')
            ->select([
                'flats.id', 'flats.number', 'flats.floor', 'flats.rooms', 'flats.is_euro', 'flats.area', 'flats.kitchen_area',
                'flats.finishing', 'flats.price', 'flats.status', 'flats.plan_url', 'flats.features',
                'buildings.complex_id', 'buildings.name as building_name', 'buildings.floors', 'buildings.deadline',
                'complexes.name as complex_name',
            ])
            ->selectRaw('round(flats.price / flats.area) as price_per_meter');

        return $filters->apply($query)
            ->orderBy(self::SORTS[$sort], $direction)
            ->orderBy('flats.id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Flat $flat): array => [
                'id' => $flat->id,
                'complex_id' => $flat->complex_id,
                'complex_name' => $flat->complex_name,
                'building_name' => $flat->building_name,
                'number' => $flat->number,
                'floor' => $flat->floor,
                'floors' => $flat->floors,
                'rooms' => $flat->rooms,
                'is_euro' => $flat->is_euro,
                'is_open_plan' => (bool) ($flat->features['open_plan'] ?? false),
                'area' => (float) $flat->area,
                'kitchen_area' => $flat->kitchen_area === null ? null : (float) $flat->kitchen_area,
                'finishing' => $flat->finishing?->label(),
                'price' => $flat->price,
                'price_per_meter' => (int) $flat->price_per_meter,
                'status' => $flat->status->value,
                'status_label' => $flat->status->label(),
                'deadline' => $flat->deadline,
                'plan_url' => $flat->plan_url,
            ]);
    }

    /**
     * Get the complexes with their place on the map, the nearest metro station and what is on sale.
     *
     * @return list<array<string, mixed>>
     */
    public function complexes(): array
    {
        $complexes = Complex::query()
            ->select(['complexes.id', 'complexes.name', 'complexes.address', 'complexes.latitude', 'complexes.longitude', 'complexes.deadline'])
            ->withCount(['flats as available_count' => fn ($query) => $query->where('flats.status', FlatStatus::Available)])
            ->withMin(['flats as min_price' => fn ($query) => $query->where('flats.status', FlatStatus::Available)], 'flats.price')
            ->orderBy('complexes.name')
            ->get();

        $nearestMetro = DB::table('complex_poi')
            ->join('pois', 'pois.id', '=', 'complex_poi.poi_id')
            ->where('pois.type', PoiType::Metro)
            ->whereIn('complex_poi.complex_id', $complexes->modelKeys())
            ->selectRaw('distinct on (complex_poi.complex_id) complex_poi.complex_id, pois.name, complex_poi.distance_m, complex_poi.walk_min')
            ->orderBy('complex_poi.complex_id')
            ->orderBy('complex_poi.distance_m')
            ->get()
            ->keyBy('complex_id');

        return $complexes->map(fn (Complex $complex): array => [
            'id' => $complex->id,
            'name' => $complex->name,
            'address' => $complex->address,
            'latitude' => $complex->latitude,
            'longitude' => $complex->longitude,
            'deadline' => $complex->deadline?->toDateString(),
            'available_count' => $complex->available_count,
            'min_price' => $complex->min_price === null ? null : (int) $complex->min_price,
            'nearest_metro' => isset($nearestMetro[$complex->id]) ? [
                'name' => $nearestMetro[$complex->id]->name,
                'distance_m' => $nearestMetro[$complex->id]->distance_m,
                'walk_min' => $nearestMetro[$complex->id]->walk_min,
            ] : null,
        ])->all();
    }

    /**
     * Get the places near the complex, nearest first, each at a point on it for the map.
     *
     * @return list<array<string, mixed>>
     */
    public function surroundings(int $complexId): array
    {
        $complex = Complex::query()->find($complexId);

        if ($complex === null) {
            return [];
        }

        return $complex->pois()
            ->select(['pois.id', 'pois.type', 'pois.name'])
            ->selectRaw('ST_Y(ST_PointOnSurface(pois.geom::geometry)) as latitude, ST_X(ST_PointOnSurface(pois.geom::geometry)) as longitude')
            ->orderBy('complex_poi.distance_m')
            ->get()
            ->map(fn (Poi $poi): array => [
                'id' => $poi->id,
                'type' => $poi->type->value,
                'name' => $poi->name ?? $poi->type->label(),
                'distance_m' => $poi->pivot->distance_m,
                'walk_min' => $poi->pivot->walk_min,
                'latitude' => (float) $poi->latitude,
                'longitude' => (float) $poi->longitude,
            ])
            ->all();
    }

    /**
     * Get the completion dates of the buildings, earliest first, to filter by.
     *
     * @return list<string>
     */
    public function deadlines(): array
    {
        return Building::query()
            ->whereNotNull('deadline')
            ->distinct()
            ->orderBy('deadline')
            ->pluck('deadline')
            ->map(fn ($deadline): string => $deadline->toDateString())
            ->all();
    }
}
