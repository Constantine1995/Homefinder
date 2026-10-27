<?php

namespace App\Models;

use App\Enums\FeedImportStatus;
use App\Enums\FeedType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FeedSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A feed or spreadsheet the developer's flats are imported from.
 */
#[Fillable(['name', 'type', 'url', 'schedule', 'is_active'])]
class FeedSource extends Model
{
    /** @use HasFactory<FeedSourceFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FeedType::class,
            'is_active' => 'boolean',
            'last_status' => FeedImportStatus::class,
            'last_imported_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<FeedImport, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(FeedImport::class);
    }

    /**
     * @return HasMany<Flat, $this>
     */
    public function flats(): HasMany
    {
        return $this->hasMany(Flat::class);
    }
}
