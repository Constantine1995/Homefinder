<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\KbDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document of the developer's knowledge base: a page about a complex, the buying FAQ, mortgage terms.
 */
#[Fillable(['complex_id', 'title', 'source', 'content', 'content_hash', 'index_version'])]
class KbDocument extends Model
{
    /** @use HasFactory<KbDocumentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Complex, $this>
     */
    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    /**
     * @return HasMany<KbChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class);
    }
}
