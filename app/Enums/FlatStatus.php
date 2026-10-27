<?php

namespace App\Enums;

/**
 * Sales status of a flat.
 */
enum FlatStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';

    /**
     * The flat is no longer present in its source feed.
     */
    case Withdrawn = 'withdrawn';

    /**
     * Get the name managers know the status by.
     */
    public function label(): string
    {
        return match ($this) {
            self::Available => 'Свободна',
            self::Reserved => 'Бронь',
            self::Sold => 'Продана',
            self::Withdrawn => 'Снята с продажи',
        };
    }
}
