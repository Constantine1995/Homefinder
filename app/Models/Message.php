<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Events\ConversationUpdated;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A message of a conversation, as the buyer, the agent or a manager wrote it; phones and emails are kept as written.
 */
#[Fillable(['role', 'user_id', 'saved_search_id', 'text', 'tool_calls', 'delivered_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Tell the open cabinets about the new message.
     */
    protected static function booted(): void
    {
        static::created(fn (Message $message) => ConversationUpdated::dispatch($message->tenant_id, $message->conversation_id));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tool_calls' => 'array',
            'delivered_at' => 'datetime',
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
     * Get the manager who wrote the message.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the subscription whose news the message tells.
     *
     * @return BelongsTo<SavedSearch, $this>
     */
    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }

    /**
     * Get the agent's log of the turn that wrote the message.
     *
     * @return HasOne<AgentRun, $this>
     */
    public function agentRun(): HasOne
    {
        return $this->hasOne(AgentRun::class);
    }
}
