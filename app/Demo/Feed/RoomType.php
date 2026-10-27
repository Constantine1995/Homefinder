<?php

namespace App\Demo\Feed;

/**
 * Kind of a room on a demo floor plan.
 */
enum RoomType
{
    /**
     * Living room of a classic flat.
     */
    case Room;

    /**
     * Bedroom of a euro-format flat, where the kitchen-living room serves as the living room.
     */
    case Bedroom;

    /**
     * The only room of a studio, with a kitchen zone.
     */
    case StudioRoom;

    case Kitchen;

    /**
     * Kitchen combined with the living room; by documents it is a kitchen, not a room.
     */
    case KitchenLiving;

    /**
     * Bathroom with a toilet.
     */
    case Bathroom;

    /**
     * Bathroom without a toilet, next to a separate toilet.
     */
    case Bath;

    case Toilet;
    case Hall;
    case Wardrobe;
    case Laundry;

    /**
     * Get the label the room has on a floor plan.
     */
    public function label(): string
    {
        return match ($this) {
            self::Room => 'Комната',
            self::Bedroom => 'Спальня',
            self::StudioRoom => 'Комната с кухней',
            self::Kitchen => 'Кухня',
            self::KitchenLiving => 'Кухня-гостиная',
            self::Bathroom => 'Санузел',
            self::Bath => 'Ванная',
            self::Toilet => 'Туалет',
            self::Hall => 'Прихожая',
            self::Wardrobe => 'Гардеробная',
            self::Laundry => 'Постирочная',
        };
    }

    /**
     * Get the label for rooms too narrow to fit the full one.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::StudioRoom => 'Комната',
            self::KitchenLiving => 'Кухня-гост.',
            self::Bathroom => 'С/у',
            self::Toilet => 'WC',
            self::Hall => 'Прих.',
            self::Wardrobe => 'Гард.',
            self::Laundry => 'Пост.',
            default => $this->label(),
        };
    }

    /**
     * Determine whether the room counts towards the living space.
     */
    public function isLiving(): bool
    {
        return in_array($this, [self::Room, self::Bedroom, self::StudioRoom], true);
    }

    /**
     * Determine whether the room is a kitchen, a kitchen-living room included.
     */
    public function isKitchen(): bool
    {
        return in_array($this, [self::Kitchen, self::KitchenLiving], true);
    }
}
