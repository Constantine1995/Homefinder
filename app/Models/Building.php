<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A building (корпус) of a residential complex.
 */
#[Fillable(['external_id', 'name', 'address', 'latitude', 'longitude', 'floors', 'deadline'])]
#[Hidden(['geom'])]
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
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
            'geocoded_at' => 'datetime',
            'deadline' => 'date',
        ];
    }

    /**
     * Geocode a new address again: what was found or not found for the old one no longer holds.
     */
    protected static function booted(): void
    {
        // The mark may have been set in the database after the model was loaded, so it is emptied there.
        static::updated(function (Building $building): void {
            if ($building->wasChanged('address')) {
                $building->newQueryWithoutScopes()->whereKey($building->getKey())->update(['geocoded_at' => null]);
                $building->setAttribute('geocoded_at', null)->syncOriginalAttribute('geocoded_at');
            }
        });
    }

    /**
     * @return BelongsTo<Complex, $this>
     */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /**
     * @return HasMany<Flat, $this>
     */
    public function flats(): HasMany
    {
        return $this->hasMany(Flat::class);
    }
}
