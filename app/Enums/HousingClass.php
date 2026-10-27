<?php

namespace App\Enums;

/**
 * Housing class of a residential complex as used on the Russian new-build market.
 */
enum HousingClass: string
{
    case Economy = 'economy';
    case Comfort = 'comfort';
    case ComfortPlus = 'comfort_plus';
    case Business = 'business';
    case Premium = 'premium';
    case Elite = 'elite';
}
