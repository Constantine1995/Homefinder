<?php

namespace App\Models;

use App\Enums\OsmType;
use App\Enums\PoiType;
use Database\Factories\PoiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A point of interest from OpenStreetMap: metro station, school, park and so on.
 *
 * Shared by all tenants; the geometry is written as EWKT, e.g. "SRID=4326;POINT(37.6173 55.7558)".
 */
#[Fillable(['osm_type', 'osm_id', 'type', 'name', 'geom', 'tags'])]
#[Hidden(['geom'])]
class Poi extends Model
{
    /** @use HasFactory<PoiFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'osm_type' => OsmType::class,
            'type' => PoiType::class,
            'tags' => 'array',
        ];
    }
}
