<?php

namespace App\Enums;

/**
 * State of a single feed import run.
 */
enum FeedImportStatus: string
{
    case Running = 'running';

    /**
     * The feed was processed; some of its rows may still have been rejected.
     */
    case Completed = 'completed';

    /**
     * The feed could not be fetched or parsed.
     */
    case Failed = 'failed';
}
