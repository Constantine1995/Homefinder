<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Events\ConversationUpdated;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A dialog of a buyer with the developer's sales side: the AI agent, or a manager once the conversation is handed over.
 */
#[Fillable(['channel', 'status', 'assignee_id', 'handoff_reason', 'dialog', 'last_message_at'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'ai',
    ];

    /**
     * Tell the open cabinets when another side starts answering the buyer.
     */
    protected static function booted(): void
    {
        static::updated(function (Conversation $conversation): void {
            if ($conversation->wasChanged(['status', 'assignee_id'])) {
                ConversationUpdated::dispatch($conversation->tenant_id, $conversation->id);
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
            'channel' => ConversationChannel::class,
            'status' => ConversationStatus::class,
            'dialog' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<AgentRun, $this>
     */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    /**
     * Get the subscriptions the buyer made in the conversation.
     *
     * @return HasMany<SavedSearch, $this>
     */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }

    /**
     * Get the lead card collected in the conversation; none until the buyer tells something about the purchase.
     *
     * @return HasOne<Lead, $this>
     */
    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }

    /**
     * Get the buyer writing from a channel; null in the test chat.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Whether the agent answers the buyer's next message.
     */
    public function isAnsweredByAgent(): bool
    {
        return $this->status === ConversationStatus::Ai;
    }

    /**
     * Hand the conversation over to the managers: the agent stops answering.
     */
    public function handOff(string $reason): void
    {
        $this->update(['status' => ConversationStatus::Human, 'handoff_reason' => $reason]);
    }

    /**
     * Have the manager answer the buyer from now on; the agent stops answering. Why the agent handed the conversation
     * over stays, a conversation taken from the agent has no such reason.
     */
    public function takeBy(User $manager): void
    {
        $this->update([
            'status' => ConversationStatus::Human,
            'assignee_id' => $manager->id,
            'handoff_reason' => $this->isAnsweredByAgent() ? null : $this->handoff_reason,
        ]);
    }

    /**
     * Have the agent answer the buyer again.
     */
    public function handBack(): void
    {
        $this->update(['status' => ConversationStatus::Ai, 'assignee_id' => null, 'handoff_reason' => null]);
    }

    /**
     * Close the conversation; the buyer's next message starts a new one.
     */
    public function close(): void
    {
        $this->update(['status' => ConversationStatus::Closed]);
    }

    /**
     * Whether the buyer's latest message waits for an answer.
     */
    public function awaitsAnswer(): bool
    {
        return $this->messages()->latest('id')->value('role') === MessageRole::Buyer;
    }
}
