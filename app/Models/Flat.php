<?php

namespace App\Models;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FlatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flat in a building; prices are in rubles, areas in square meters.
 */
#[Fillable([
    'external_id',
    'number',
    'floor',
    'rooms',
    'is_euro',
    'area',
    'kitchen_area',
    'finishing',
    'price',
    'status',
    'plan_url',
    'description',
    'features',
])]
#[Hidden(['embedding'])]
class Flat extends Model
{
    /** @use HasFactory<FlatFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        // The vector describes the description and features; a new one is computed by FlatEmbedder.
        static::saving(function (Flat $flat): void {
            if ($flat->exists && $flat->isDirty(['description', 'features']) && ! $flat->isDirty('embedding')) {
                $flat->embedding = null;
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_euro' => 'boolean',
            'area' => 'decimal:2',
            'kitchen_area' => 'decimal:2',
            'finishing' => Finishing::class,
            'status' => FlatStatus::class,
            'features' => 'array',
            'embedding' => AsVector::class,
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<FeedSource, $this>
     */
    public function feedSource(): BelongsTo
    {
        return $this->belongsTo(FeedSource::class);
    }

    /**
     * Get the history of the flat's price and status changes.
     *
     * @return HasMany<FlatEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(FlatEvent::class);
    }
}
