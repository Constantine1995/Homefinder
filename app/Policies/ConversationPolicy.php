<?php

namespace App\Policies;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;

/**
 * What an employee may do in the shared chat. Everyone sees all of the developer's conversations (the tenant scope
 * keeps out the rest); a manager acts on the unassigned ones and their own, the sales head and the admin on any.
 * Only the manager who took a conversation writes in it, so the buyer hears one person.
 */
class ConversationPolicy
{
    /**
     * Take the conversation: the agent stops answering, and the manager does.
     */
    public function take(User $user, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed && $this->manages($user, $conversation);
    }

    /**
     * Write to the buyer.
     */
    public function reply(User $user, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Human && $conversation->assignee_id === $user->id;
    }

    /**
     * Hand the conversation back to the agent.
     */
    public function handBack(User $user, Conversation $conversation): bool
    {
        return $conversation->status === ConversationStatus::Human && $this->manages($user, $conversation);
    }

    /**
     * Close the conversation; the buyer's next message starts a new one.
     */
    public function close(User $user, Conversation $conversation): bool
    {
        return $conversation->status !== ConversationStatus::Closed && $this->manages($user, $conversation);
    }

    private function manages(User $user, Conversation $conversation): bool
    {
        return $conversation->assignee_id === null
            || $conversation->assignee_id === $user->id
            || $user->role->supervisesConversations();
    }
}
