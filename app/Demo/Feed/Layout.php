<?php

namespace App\Demo\Feed;

/**
 * A typical flat layout ("планировка") of the demo developer.
 *
 * The plan is two bands of rooms across the flat: rooms with windows along the facade
 * and service rooms along the corridor. Rooms of a band share its depth; sizes are in meters.
 */
readonly class Layout
{
    /**
     * @var list<array{type: RoomType, x: float, y: float, width: float, depth: float, area: float}>
     */
    private array $placedRooms;

    /**
     * @param  list<array{RoomType, float}>  $facadeRooms  Rooms with windows from left to right, with their widths.
     * @param  list<array{RoomType, float}>  $innerRooms  Service rooms from left to right, with their widths.
     * @param  ?string  $balcony  Balcony type as Yandex names it: "балкон" or "лоджия".
     * @param  int  $balconyRoom  Index of the facade room the balcony adjoins.
     */
    public function __construct(
        public string $code,
        public float $facadeDepth,
        public array $facadeRooms,
        public float $innerDepth,
        public array $innerRooms,
        public ?string $balcony = null,
        public int $balconyRoom = 0,
    ) {
        $this->placedRooms = [
            ...$this->placeBand($facadeRooms, 0.0, $facadeDepth),
            ...$this->placeBand($innerRooms, $facadeDepth, $innerDepth),
        ];
    }

    /**
     * Get the rooms placed on the plan; the facade runs along the top edge.
     *
     * @return list<array{type: RoomType, x: float, y: float, width: float, depth: float, area: float}>
     */
    public function rooms(): array
    {
        return $this->placedRooms;
    }

    /**
     * Get the width of the flat along the facade.
     */
    public function width(): float
    {
        return array_sum(array_column($this->facadeRooms, 1));
    }

    /**
     * Get the depth of the flat from the facade to the corridor.
     */
    public function depth(): float
    {
        return $this->facadeDepth + $this->innerDepth;
    }

    /**
     * Get the total area of the rooms, balcony excluded.
     */
    public function area(): float
    {
        return $this->sumAreas(fn (RoomType $type): bool => true);
    }

    /**
     * Get the area of the living rooms; in a euro-format flat that is the bedrooms only.
     */
    public function livingSpace(): float
    {
        return $this->sumAreas(fn (RoomType $type): bool => $type->isLiving());
    }

    /**
     * Get the area of the kitchen or kitchen-living room; a studio has none.
     */
    public function kitchenSpace(): ?float
    {
        return $this->isStudio() ? null : $this->sumAreas(fn (RoomType $type): bool => $type->isKitchen());
    }

    /**
     * Get the areas of the rooms counted as rooms by documents; a kitchen-living room is not one.
     *
     * @return list<float>
     */
    public function roomSpaces(): array
    {
        return array_column($this->roomsOf(RoomType::Room, RoomType::Bedroom), 'area');
    }

    /**
     * Get the number of rooms by documents: a euro two-room flat has one, a studio none.
     */
    public function roomCount(): int
    {
        return count($this->roomSpaces());
    }

    /**
     * Determine whether the flat is a studio: one room with a kitchen zone.
     */
    public function isStudio(): bool
    {
        return $this->roomsOf(RoomType::StudioRoom) !== [];
    }

    /**
     * Determine whether the flat is in the euro format, with a kitchen-living room instead of a living room.
     */
    public function isEuro(): bool
    {
        return $this->roomsOf(RoomType::KitchenLiving) !== [];
    }

    /**
     * Determine whether the flat has a room of the given type.
     */
    public function hasRoom(RoomType $type): bool
    {
        return $this->roomsOf($type) !== [];
    }

    /**
     * Get the name buyers know the layout by, e.g. "Евродвушка".
     */
    public function title(): string
    {
        if ($this->isStudio()) {
            return 'Студия';
        }

        if ($this->isEuro()) {
            return match ($this->roomCount() + 1) {
                2 => 'Евродвушка',
                3 => 'Евротрёшка',
                4 => 'Евро-четырёхкомнатная квартира',
            };
        }

        return match ($this->roomCount()) {
            1 => 'Однокомнатная квартира',
            2 => 'Двухкомнатная квартира',
            3 => 'Трёхкомнатная квартира',
        };
    }

    /**
     * Get the bathroom type as Yandex names it: "совмещенный", "раздельный" or the number of bathrooms.
     */
    public function bathroomUnit(): string
    {
        $bathrooms = count($this->roomsOf(RoomType::Bathroom));

        return match (true) {
            $this->hasRoom(RoomType::Bath) && $this->hasRoom(RoomType::Toilet) => 'раздельный',
            $bathrooms > 1 => (string) $bathrooms,
            default => 'совмещенный',
        };
    }

    /**
     * @param  list<array{RoomType, float}>  $rooms
     * @return list<array{type: RoomType, x: float, y: float, width: float, depth: float, area: float}>
     */
    private function placeBand(array $rooms, float $y, float $depth): array
    {
        $placed = [];
        $x = 0.0;

        foreach ($rooms as [$type, $width]) {
            $placed[] = [
                'type' => $type,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'depth' => $depth,
                'area' => $this->rectangleArea($width, $depth),
            ];

            $x += $width;
        }

        return $placed;
    }

    /**
     * Get the area rounded half up to 0.1 m² as technical passports do.
     *
     * Computed in whole square decimeters, so the result does not depend on float rounding.
     */
    private function rectangleArea(float $width, float $depth): float
    {
        $squareDecimeters = (int) round($width * 10) * (int) round($depth * 10);

        return intdiv($squareDecimeters + 5, 10) / 10;
    }

    /**
     * @param  callable(RoomType): bool  $filter
     */
    private function sumAreas(callable $filter): float
    {
        $tenths = 0;

        foreach ($this->rooms() as $room) {
            if ($filter($room['type'])) {
                $tenths += (int) round($room['area'] * 10);
            }
        }

        return $tenths / 10;
    }

    /**
     * @return list<array{type: RoomType, x: float, y: float, width: float, depth: float, area: float}>
     */
    private function roomsOf(RoomType ...$types): array
    {
        return array_values(array_filter(
            $this->rooms(),
            fn (array $room): bool => in_array($room['type'], $types, true),
        ));
    }
}
