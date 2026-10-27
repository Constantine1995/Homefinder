<?php

namespace App\Models;

use App\Enums\FeedImportStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FeedImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single run of a feed import together with its report.
 */
#[Fillable([
    'status',
    'file_name',
    'file_path',
    'added_count',
    'updated_count',
    'removed_count',
    'failed_count',
    'row_errors',
    'error_message',
    'finished_at',
])]
class FeedImport extends Model
{
    /** @use HasFactory<FeedImportFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FeedImportStatus::class,
            'row_errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FeedSource, $this>
     */
    public function feedSource(): BelongsTo
    {
        return $this->belongsTo(FeedSource::class);
    }

    /**
     * Get the price and status changes the import found.
     *
     * @return HasMany<FlatEvent, $this>
     */
    public function flatEvents(): HasMany
    {
        return $this->hasMany(FlatEvent::class);
    }
}
