<?php

namespace App\Services\Geo;

use App\Enums\OsmType;
use App\Enums\PoiType;
use App\Models\Poi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Brings the points of interest in line with one copy of the region's OpenStreetMap data: adds and updates
 * metro stations, schools, kindergartens, parks and shops, and deletes the ones the data no longer has,
 * together with their distances to complexes.
 */
class PoiImporter
{
    /**
     * Number of points of interest written together.
     */
    private const int BATCH_SIZE = 500;

    /**
     * Shops that buyers mean by «магазины рядом».
     *
     * @var list<string>
     */
    private const array SHOPS = ['supermarket', 'convenience', 'mall'];

    /**
     * OSM element types by the first letter of the feature IDs osmium makes.
     *
     * @var array<string, OsmType>
     */
    private const array OSM_TYPES = ['n' => OsmType::Node, 'w' => OsmType::Way, 'r' => OsmType::Relation];

    /**
     * Import the points of interest of a GeoJSON text sequence that osmium exported, in one transaction.
     *
     * @return array{counts: array<string, int>, removed: int} Points of interest by type, and how many were deleted.
     *
     * @throws GeoException when the data has no points of interest: deleting them all would empty every surroundings
     */
    public function import(string $path): array
    {
        $file = @fopen($path, 'r') ?: throw new GeoException("The OpenStreetMap export {$path} could not be opened.");

        try {
            return DB::transaction(fn (): array => $this->importFile($file));
        } finally {
            fclose($file);
        }
    }

    /**
     * @param  resource  $file
     * @return array{counts: array<string, int>, removed: int}
     */
    private function importFile($file): array
    {
        $importedAt = now()->startOfSecond();
        $counts = array_fill_keys(array_map(fn (PoiType $type): string => $type->value, PoiType::cases()), 0);
        $batch = [];

        while (($line = fgets($file)) !== false) {
            $poi = $this->poi(json_decode(ltrim($line, "\x1E"), true));

            if ($poi === null) {
                continue;
            }

            $counts[$poi['type']]++;
            $batch[] = $poi;

            if (count($batch) === self::BATCH_SIZE) {
                $this->save($batch, $importedAt->toDateTimeString());
                $batch = [];
            }
        }

        $this->save($batch, $importedAt->toDateTimeString());

        if (array_sum($counts) === 0) {
            throw new GeoException('The OpenStreetMap export has no points of interest.');
        }

        return ['counts' => $counts, 'removed' => Poi::where('updated_at', '<', $importedAt)->delete()];
    }

    /**
     * Translate a feature into a point of interest, or null if it is not one.
     *
     * @return array{osm_type: string, osm_id: int, type: string, name: ?string, geometry: string, tags: string}|null
     */
    private function poi(mixed $feature): ?array
    {
        if (! is_array($feature) || ! is_array($feature['properties'] ?? null) || ! is_array($feature['geometry'] ?? null)) {
            return null;
        }

        $tags = $feature['properties'];
        $type = $this->type($tags, (string) ($feature['geometry']['type'] ?? ''));

        $osmObject = $this->osmObject((string) ($feature['id'] ?? ''));

        if ($type === null || $osmObject === null) {
            return null;
        }

        return [
            'osm_type' => $osmObject[0]->value,
            'osm_id' => $osmObject[1],
            'type' => $type->value,
            'name' => isset($tags['name']) ? Str::limit((string) $tags['name'], 255, '') : null,
            'geometry' => json_encode($feature['geometry'], JSON_THROW_ON_ERROR),
            'tags' => json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Get the OSM object a feature ID of osmium stands for: «n12» for node 12, «w12» for way 12. An area
     * gets «a» and twice the ID of its object: «a24» is the area of way 12, «a25» of relation 12.
     *
     * @return array{OsmType, int}|null
     */
    private function osmObject(string $featureId): ?array
    {
        if (! preg_match('/^([nwra])(\d+)$/', $featureId, $matches)) {
            return null;
        }

        $id = (int) $matches[2];

        return match ($matches[1]) {
            'a' => $id % 2 === 0 ? [OsmType::Way, intdiv($id, 2)] : [OsmType::Relation, intdiv($id, 2)],
            default => [self::OSM_TYPES[$matches[1]], $id],
        };
    }

    /**
     * Tell the type of the point of interest by the object's tags. Metro stations are station nodes:
     * the metro, the Moscow Central Circle and the monorail, but not the commuter trains of МЦД.
     * A park needs an area, as small parks are left out by their area.
     *
     * @param  array<string, mixed>  $tags
     */
    private function type(array $tags, string $geometryType): ?PoiType
    {
        return match (true) {
            ($tags['railway'] ?? null) === 'station' && ($tags['station'] ?? null) === 'subway' && $geometryType === 'Point' => PoiType::Metro,
            ($tags['amenity'] ?? null) === 'school' => PoiType::School,
            ($tags['amenity'] ?? null) === 'kindergarten' => PoiType::Kindergarten,
            ($tags['leisure'] ?? null) === 'park' && $geometryType !== 'Point' => PoiType::Park,
            in_array($tags['shop'] ?? null, self::SHOPS, true) => PoiType::Shop,
            default => null,
        };
    }

    /**
     * Insert or update the points of interest, building their geometry from GeoJSON in the database.
     *
     * @param  list<array{osm_type: string, osm_id: int, type: string, name: ?string, geometry: string, tags: string}>  $pois
     */
    private function save(array $pois, string $importedAt): void
    {
        if ($pois === []) {
            return;
        }

        $rows = implode(', ', array_fill(0, count($pois), '(?, ?, ?, ?, ST_GeomFromGeoJSON(?)::geography, ?::jsonb, ?, ?)'));
        $bindings = array_merge(...array_map(
            fn (array $poi): array => [...array_values($poi), $importedAt, $importedAt],
            $pois,
        ));

        DB::insert(<<<SQL
            insert into pois (osm_type, osm_id, type, name, geom, tags, created_at, updated_at)
            values {$rows}
            on conflict (osm_type, osm_id) do update set
                type = excluded.type,
                name = excluded.name,
                geom = excluded.geom,
                tags = excluded.tags,
                updated_at = excluded.updated_at
            SQL, $bindings);
    }
}
