<?php

namespace App\Enums;

/**
 * Category of a point of interest imported from OpenStreetMap.
 */
enum PoiType: string
{
    case Metro = 'metro';
    case School = 'school';
    case Kindergarten = 'kindergarten';
    case Park = 'park';
    case Shop = 'shop';

    /**
     * Get the name of the kind of place, used for places that have no name of their own.
     */
    public function label(): string
    {
        return match ($this) {
            self::Metro => 'Метро',
            self::School => 'Школа',
            self::Kindergarten => 'Детский сад',
            self::Park => 'Парк',
            self::Shop => 'Магазин',
        };
    }
}
