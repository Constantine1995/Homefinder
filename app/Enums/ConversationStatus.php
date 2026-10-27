<?php

namespace App\Enums;

/**
 * Who answers the buyer in a conversation.
 */
enum ConversationStatus: string
{
    /**
     * The AI agent answers.
     */
    case Ai = 'ai';

    /**
     * A manager has taken the conversation, or the agent handed it over: the agent stays silent.
     */
    case Human = 'human';

    case Closed = 'closed';
}
