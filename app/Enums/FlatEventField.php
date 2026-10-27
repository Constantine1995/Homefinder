<?php

namespace App\Enums;

/**
 * Flat attribute whose changes are kept in the flat history.
 */
enum FlatEventField: string
{
    case Price = 'price';
    case Status = 'status';
}
