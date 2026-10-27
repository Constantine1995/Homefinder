<?php

namespace App\Demo\Feed;

use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * A flat in the demo developer's inventory, sold or not.
 */
readonly class DemoFlat
{
    /**
     * Price premium of each finishing, by its Yandex name.
     *
     * @var array<string, float>
     */
    private const array FINISHING_PREMIUMS = [
        'без отделки' => 1.0,
        'черновая' => 1.0,
        'предчистовая' => 1.02,
        'whitebox' => 1.02,
        'чистовая' => 1.06,
        'под ключ' => 1.1,
    ];

    /**
     * What each finishing includes, by its Yandex name.
     *
     * @var array<string, string>
     */
    private const array FINISHING_NOTES = [
        'без отделки' => 'Без отделки: ремонт можно сделать по своему проекту.',
        'черновая' => 'Черновая отделка: стяжка пола и штукатурка стен.',
        'предчистовая' => 'Предчистовая отделка: стены и пол готовы к финишным покрытиям.',
        'whitebox' => 'Отделка white box: стены и пол подготовлены под чистовую отделку.',
        'чистовая' => 'Чистовая отделка от застройщика: можно въезжать сразу после получения ключей.',
        'под ключ' => 'Квартира под ключ: отделка, кухня и встроенная мебель.',
    ];

    public function __construct(
        public DemoComplex $complex,
        public DemoBuilding $building,
        public FloorPosition $position,
        public Layout $layout,
        public int $floor,
        public int $number,
    ) {}

    /**
     * Round a price to whole thousands of rubles, as developers price their flats.
     */
    public static function roundPrice(float $rubles): int
    {
        return (int) (floor($rubles / 1000 + 0.5) * 1000);
    }

    /**
     * Get the flat's ID in the developer's system and in the feed, e.g. "OK-1-57".
     */
    public function id(): string
    {
        return "{$this->complex->code}-{$this->building->number}-{$this->number}";
    }

    /**
     * Get the price the flat was put on sale at, before any discounts and price rises.
     */
    public function basePrice(): int
    {
        $isTopFloor = $this->floor === $this->building->floors;

        return self::roundPrice(
            $this->layout->area()
            * $this->complex->pricePerSquareMeter
            * (1 + 0.004 * ($this->floor - 2))
            * ($isTopFloor ? 1.02 : 1.0)
            * $this->layoutPremium()
            * ($this->position->landmark === null ? 1.0 : 1.04)
            * self::FINISHING_PREMIUMS[$this->building->renovation]
            * $this->deadlinePremium(),
        );
    }

    /**
     * Get the description of the flat as the developer publishes it.
     */
    public function description(): string
    {
        return implode(' ', array_filter([
            sprintf(
                '%s %s на %d-м этаже %d-этажного корпуса.',
                $this->layout->title(),
                $this->squareMeters($this->layout->area()),
                $this->floor,
                $this->building->floors,
            ),
            $this->roomsSentence(),
            $this->windowsSentence(),
            $this->viewSentence(),
            ...$this->amenitySentences(),
            self::FINISHING_NOTES[$this->building->renovation],
            $this->complex->tagline,
        ]));
    }

    /**
     * Smaller flats cost more per square meter.
     */
    private function layoutPremium(): float
    {
        if ($this->layout->isStudio()) {
            return 1.07;
        }

        $rooms = $this->layout->roomCount() + ($this->layout->isEuro() ? 1 : 0);

        return 1.04 - 0.02 * $rooms + ($this->layout->isEuro() ? 0.01 : 0.0);
    }

    /**
     * Completed buildings cost more, buildings completed in more than a year cost less.
     */
    private function deadlinePremium(): float
    {
        return match (true) {
            $this->building->state === 'hand-over' => 1.05,
            $this->building->state === 'built' => 1.03,
            $this->building->readyYear - DemoWorld::firstDay()->year > 1 => 0.97,
            default => 1.0,
        };
    }

    private function roomsSentence(): string
    {
        $layout = $this->layout;

        if ($layout->isStudio()) {
            return "Комната с кухонной зоной {$this->squareMeters($layout->livingSpace())}.";
        }

        $rooms = array_map($this->squareMeters(...), $layout->roomSpaces());
        $kitchen = $this->squareMeters((float) $layout->kitchenSpace());

        if ($layout->isEuro()) {
            return count($rooms) === 1
                ? "Кухня-гостиная {$kitchen} и спальня {$rooms[0]}."
                : "Кухня-гостиная {$kitchen} и {$this->countWord(count($rooms))} спальни: {$this->listOf($rooms)}.";
        }

        return count($rooms) === 1
            ? "Комната {$rooms[0]}, кухня {$kitchen}."
            : "{$this->countWord(count($rooms), capitalized: true)} изолированные комнаты: {$this->listOf($rooms)}, кухня {$kitchen}.";
    }

    private function windowsSentence(): string
    {
        $view = $this->position->windowView === 'во двор' ? 'в тихий двор' : $this->position->windowView;
        $sentence = sprintf('Окна выходят на %s, %s.', implode(' и ', $this->position->sides), $view);

        $isSunny = array_intersect($this->position->sides, ['юг', 'запад']) !== [];

        return $isSunny ? $sentence.' Солнечная сторона.' : $sentence;
    }

    private function viewSentence(): ?string
    {
        if ($this->position->landmark !== null) {
            return "Вид на {$this->position->landmark}.";
        }

        return $this->floor > $this->building->floors - 4 ? 'С высоты открывается вид на город.' : null;
    }

    /**
     * @return list<string>
     */
    private function amenitySentences(): array
    {
        $layout = $this->layout;

        return array_values(array_filter([
            match ($layout->balcony) {
                'лоджия' => 'Застеклённая лоджия.',
                'балкон' => 'Балкон.',
                default => null,
            },
            $layout->hasRoom(RoomType::Wardrobe) ? 'Гардеробная.' : null,
            $layout->hasRoom(RoomType::Laundry) ? 'Постирочная.' : null,
            match ($layout->bathroomUnit()) {
                'совмещенный' => 'Совмещённый санузел.',
                'раздельный' => 'Раздельный санузел.',
                default => 'Два санузла.',
            },
            $this->building->ceilingHeight >= 3.0
                ? 'Потолки '.Number::format($this->building->ceilingHeight, maxPrecision: 2, locale: 'ru').' м.'
                : null,
        ]));
    }

    private function squareMeters(float $area): string
    {
        return Number::format($area, maxPrecision: 1, locale: 'ru').' м²';
    }

    /**
     * @param  list<string>  $items
     */
    private function listOf(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' и '.$last;
    }

    private function countWord(int $count, bool $capitalized = false): string
    {
        $word = [2 => 'две', 3 => 'три'][$count];

        return $capitalized ? Str::ucfirst($word) : $word;
    }
}
