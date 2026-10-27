<?php

namespace App\Enums;

/**
 * Which conversations the shared chat lists.
 */
enum InboxFilter: string
{
    /**
     * Not closed: answered by the agent or by a manager.
     */
    case Open = 'open';

    /**
     * Handed over to the managers and taken by none of them yet.
     */
    case Waiting = 'waiting';

    /**
     * Taken by the employee and not closed.
     */
    case Mine = 'mine';

    case Closed = 'closed';
}
