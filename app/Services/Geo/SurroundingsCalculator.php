<?php

namespace App\Services\Geo;

use App\Enums\PoiType;
use App\Models\Complex;
use App\Models\Scopes\TenantScope;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;

/**
 * Computes the surroundings of complexes ahead of time: the points of interest near each complex,
 * the distance to each of them and the minutes it takes to walk there.
 *
 * The distance runs from the complex's point to the object's geometry, so to the edge of a park.
 */
class SurroundingsCalculator
{
    /**
     * Number of complexes computed in one query.
     */
    private const int BATCH_SIZE = 100;

    public function __construct(
        #[Config('geo.metro_radius')] private int $metroRadius,
        #[Config('geo.nearby_radius')] private int $nearbyRadius,
        #[Config('geo.park_min_area')] private int $parkMinArea,
        #[Config('geo.walk_detour_factor')] private float $walkDetourFactor,
        #[Config('geo.walk_meters_per_minute')] private int $walkMetersPerMinute,
    ) {}

    /**
     * Forget the surroundings of the complexes of every tenant, as after the points of interest are imported anew.
     */
    public function forgetAll(): void
    {
        Complex::withoutGlobalScope(TenantScope::class)->update(['pois_computed_at' => null]);
    }

    /**
     * Compute the surroundings of the complexes that moved, are new or were forgotten since they were last computed.
     *
     * @return int The number of complexes computed.
     */
    public function computePending(): int
    {
        $complexIds = Complex::whereNull('pois_computed_at')->orderBy('id')->pluck('id');

        foreach ($complexIds->chunk(self::BATCH_SIZE) as $ids) {
            $this->compute($ids->values()->all());
        }

        return $complexIds->count();
    }

    /**
     * Replace the surroundings of the complexes; a complex without coordinates is left with none.
     *
     * @param  list<int>  $complexIds
     */
    private function compute(array $complexIds): void
    {
        $placeholders = implode(', ', array_fill(0, count($complexIds), '?'));

        DB::transaction(function () use ($complexIds, $placeholders): void {
            DB::table('complex_poi')->whereIn('complex_id', $complexIds)->delete();

            DB::insert(<<<SQL
                insert into complex_poi (tenant_id, complex_id, poi_id, distance_m, walk_min)
                select complexes.tenant_id, complexes.id, pois.id, distance.meters,
                    greatest(1, ceil(distance.meters * ?::float / ?::integer))
                from complexes
                join pois on ST_DWithin(pois.geom, complexes.geom, ?::integer)
                cross join lateral (select round(ST_Distance(pois.geom, complexes.geom))::integer as meters) as distance
                where complexes.id in ({$placeholders})
                    and distance.meters <= case when pois.type = ? then ?::integer else ?::integer end
                    and (pois.type <> ? or ST_Area(pois.geom) >= ?::integer)
                SQL, [
                $this->walkDetourFactor,
                $this->walkMetersPerMinute,
                max($this->metroRadius, $this->nearbyRadius),
                ...$complexIds,
                PoiType::Metro->value,
                $this->metroRadius,
                $this->nearbyRadius,
                PoiType::Park->value,
                $this->parkMinArea,
            ]);

            Complex::whereKey($complexIds)->update(['pois_computed_at' => now()]);
        });
    }
}
