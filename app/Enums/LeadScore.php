<?php

namespace App\Enums;

/**
 * How ready to buy a lead is.
 */
enum LeadScore: string
{
    /**
     * Knows what and how to buy, and wants to come or to be called.
     */
    case Hot = 'hot';

    /**
     * Has told something of what and how to buy, or wants to come or to be called.
     */
    case Warm = 'warm';

    /**
     * Has told next to nothing yet.
     */
    case Cold = 'cold';
}
