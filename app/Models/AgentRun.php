<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AgentRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The agent's log of one turn: what it understood, which tools it called, whether the reply passed the verifier.
 */
#[Fillable([
    'conversation_id',
    'message_id',
    'intent',
    'steps',
    'filters',
    'actions',
    'verified',
    'problems',
    'attempts',
    'prompt_tokens',
    'completion_tokens',
    'cost',
    'duration_ms',
    'error',
])]
class AgentRun extends Model
{
    /** @use HasFactory<AgentRunFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'filters' => 'array',
            'actions' => 'array',
            'verified' => 'boolean',
            'problems' => 'array',
            'cost' => 'decimal:4',
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
     * Get the agent's reply the run wrote.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
