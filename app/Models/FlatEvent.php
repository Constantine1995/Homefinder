<?php

namespace App\Models;

use App\Enums\FlatEventField;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FlatEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded change of a flat's price or status.
 */
#[Fillable(['field', 'old_value', 'new_value'])]
class FlatEvent extends Model
{
    /** @use HasFactory<FlatEventFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Events are never updated, so only their creation time is kept.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field' => FlatEventField::class,
            'old_value' => 'json',
            'new_value' => 'json',
        ];
    }

    /**
     * @return BelongsTo<Flat, $this>
     */
    public function flat(): BelongsTo
    {
        return $this->belongsTo(Flat::class);
    }

    /**
     * Get the import that detected the change, if the change came from a feed.
     *
     * @return BelongsTo<FeedImport, $this>
     */
    public function feedImport(): BelongsTo
    {
        return $this->belongsTo(FeedImport::class);
    }
}
