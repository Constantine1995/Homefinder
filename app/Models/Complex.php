<?php

namespace App\Models;

use App\Enums\HousingClass;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ComplexFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A residential complex.
 */
#[Fillable(['external_id', 'name', 'address', 'latitude', 'longitude', 'deadline', 'housing_class', 'description'])]
#[Hidden(['geom'])]
class Complex extends Model
{
    /** @use HasFactory<ComplexFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'pois_computed_at' => 'datetime',
            'deadline' => 'date',
            'housing_class' => HousingClass::class,
        ];
    }

    /**
     * Forget the distances to points of interest once the complex moves, so that they are computed again.
     */
    protected static function booted(): void
    {
        // The mark may have been set in the database after the model was loaded, so it is emptied there.
        static::updated(function (Complex $complex): void {
            if ($complex->wasChanged(['latitude', 'longitude'])) {
                $complex->newQueryWithoutScopes()->whereKey($complex->getKey())->update(['pois_computed_at' => null]);
                $complex->setAttribute('pois_computed_at', null)->syncOriginalAttribute('pois_computed_at');
            }
        });
    }

    /**
     * Place the complex by its buildings, as feeds and spreadsheets describe buildings only: at the address
     * of the first building and the coordinates of the first located one, completed when the last building is.
     */
    public function placeAtBuildings(): void
    {
        $buildings = $this->buildings()
            ->orderBy('external_id')
            ->get(['address', 'latitude', 'longitude', 'deadline']);
        $locatedBuilding = $buildings->first(fn (Building $building): bool => $building->latitude !== null);

        $this->fill([
            'address' => $buildings->first()?->address,
            'latitude' => $locatedBuilding?->latitude,
            'longitude' => $locatedBuilding?->longitude,
            'deadline' => $buildings->max('deadline'),
        ])->save();
    }

    /**
     * @return HasMany<Building, $this>
     */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    /**
     * @return HasManyThrough<Flat, Building, $this>
     */
    public function flats(): HasManyThrough
    {
        return $this->hasManyThrough(Flat::class, Building::class);
    }

    /**
     * Get the points of interest near the complex along with the distance to each of them.
     *
     * @return BelongsToMany<Poi, $this>
     */
    public function pois(): BelongsToMany
    {
        return $this->belongsToMany(Poi::class)->withPivot(['distance_m', 'walk_min']);
    }
}
