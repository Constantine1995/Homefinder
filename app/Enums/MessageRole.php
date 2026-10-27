<?php

namespace App\Enums;

/**
 * Who wrote a message of a conversation.
 */
enum MessageRole: string
{
    case Buyer = 'buyer';

    /**
     * The AI agent.
     */
    case Agent = 'agent';

    case Manager = 'manager';

    /**
     * The role the agent service gives the message in the dialog history: the buyer is the user, the sales side
     * is the assistant, whoever of it wrote.
     */
    public function agentRole(): string
    {
        return $this === self::Buyer ? 'user' : 'assistant';
    }
}
