<?php

namespace App\Enums;

/**
 * Role of a developer's employee in the sales cabinet.
 */
enum UserRole: string
{
    /**
     * Manages feeds, settings and the knowledge base.
     */
    case Admin = 'admin';

    /**
     * Follows the sales statistics.
     */
    case SalesHead = 'sales_head';

    /**
     * Works with the shared chat, leads and viewings.
     */
    case Manager = 'manager';

    /**
     * Whether the employee may take, hand back and close a conversation another manager has taken.
     */
    public function supervisesConversations(): bool
    {
        return $this !== self::Manager;
    }

    /**
     * Whether the employee sees the sales statistics.
     */
    public function seesStatistics(): bool
    {
        return $this !== self::Manager;
    }
}
