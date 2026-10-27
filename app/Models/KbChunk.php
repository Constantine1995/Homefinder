<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\KbChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A section of a knowledge base document, the unit the agent searches and cites.
 */
#[Fillable(['position', 'section', 'text'])]
#[Hidden(['embedding', 'search'])]
class KbChunk extends Model
{
    /** @use HasFactory<KbChunkFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => AsVector::class,
        ];
    }

    /**
     * @return BelongsTo<KbDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'kb_document_id');
    }
}
