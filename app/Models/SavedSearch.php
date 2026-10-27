<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Catalog\FlatFilters;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's request to be told about new and cheaper flats that fit: «напишите, если появится двушка до 9 млн».
 *
 * Only the buyer creates one, by asking the agent; one button in the message with the news ends it.
 */
#[Fillable(['conversation_id', 'contact_id', 'description', 'filters', 'active', 'last_notified_at'])]
class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'active' => 'boolean',
            'last_notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the buyer; null for a test conversation.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The catalog's filters for the agent's search conditions.
     */
    public function flatFilters(): FlatFilters
    {
        return FlatFilters::fromAgent($this->filters);
    }
}
