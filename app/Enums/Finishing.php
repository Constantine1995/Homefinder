<?php

namespace App\Enums;

/**
 * Finishing level a flat is sold with.
 */
enum Finishing: string
{
    /**
     * No finishing ("без отделки", "черновая").
     */
    case Rough = 'rough';

    /**
     * Pre-finishing ("предчистовая", "white box").
     */
    case WhiteBox = 'white_box';

    /**
     * Ready to move in ("чистовая").
     */
    case Finished = 'finished';

    /**
     * Finished, with a kitchen and furniture ("под ключ", "с мебелью").
     */
    case Furnished = 'furnished';

    /**
     * Get the name buyers and managers know the finishing by.
     */
    public function label(): string
    {
        return match ($this) {
            self::Rough => 'Без отделки',
            self::WhiteBox => 'White box',
            self::Finished => 'Чистовая',
            self::Furnished => 'Под ключ',
        };
    }
}
