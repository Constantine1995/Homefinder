<?php

namespace App\Demo\Feed;

use Carbon\CarbonImmutable;

/**
 * The demo developer: three made-up residential complexes at real Moscow addresses,
 * their buildings, typical layouts and every flat built in them.
 */
class DemoWorld
{
    /**
     * Calendar date of day 0 of the sales simulation.
     */
    public const string FIRST_DAY = '2026-10-19';

    public const string TIMEZONE = 'Europe/Moscow';

    public const string SELLER_NAME = 'Демо-застройщик';

    public const string SELLER_PHONE = '+74950000000';

    /**
     * @var array<string, Layout>|null
     */
    private ?array $layouts = null;

    /**
     * @var list<DemoComplex>|null
     */
    private ?array $complexes = null;

    /**
     * @var list<DemoFlat>|null
     */
    private ?array $flats = null;

    /**
     * @var array<string, int>
     */
    private array $daysOfDates = [];

    /**
     * Get the moment of day 0 of the simulation; the developer updates its feed at 9 a.m.
     */
    public static function firstDay(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::FIRST_DAY.' 09:00', self::TIMEZONE);
    }

    /**
     * Get the moment the feed is generated on the given day of the simulation.
     */
    public function dateOf(int $day): CarbonImmutable
    {
        return self::firstDay()->addDays($day);
    }

    /**
     * Get the day of the simulation a calendar date falls on; days before the simulation are negative.
     */
    public function dayOf(string $date): int
    {
        return $this->daysOfDates[$date] ??= (int) round(
            self::firstDay()->startOfDay()->diffInDays(CarbonImmutable::parse($date, self::TIMEZONE)),
        );
    }

    /**
     * Get a layout by its code, e.g. "2E-42".
     */
    public function layout(string $code): ?Layout
    {
        return $this->layouts()[$code] ?? null;
    }

    /**
     * Get the developer's typical layouts keyed by their codes.
     *
     * @return array<string, Layout>
     */
    public function layouts(): array
    {
        return $this->layouts ??= array_column($this->defineLayouts(), null, 'code');
    }

    /**
     * Get the developer's residential complexes with their buildings.
     *
     * @return list<DemoComplex>
     */
    public function complexes(): array
    {
        return $this->complexes ??= $this->defineComplexes();
    }

    /**
     * Get every flat of every building, numbered within its building from the lowest residential floor up.
     *
     * @return list<DemoFlat>
     */
    public function flats(): array
    {
        if ($this->flats !== null) {
            return $this->flats;
        }

        $flats = [];

        foreach ($this->complexes() as $complex) {
            foreach ($complex->buildings as $building) {
                $number = 0;

                for ($floor = 2; $floor <= $building->floors; $floor++) {
                    foreach ($building->floorPlate as $position) {
                        $layout = $this->layouts()[$position->layout];
                        $flats[] = new DemoFlat($complex, $building, $position, $layout, $floor, ++$number);
                    }
                }
            }
        }

        return $this->flats = $flats;
    }

    /**
     * Kitchen-living rooms of euro layouts are at least 15 m², kitchens of classic layouts are smaller.
     *
     * @return list<Layout>
     */
    private function defineLayouts(): array
    {
        return [
            new Layout('S-24', 4.4, [[RoomType::StudioRoom, 3.7]], 2.1, [[RoomType::Bathroom, 1.7], [RoomType::Hall, 2.0]]),
            new Layout(
                'S-28', 4.8, [[RoomType::StudioRoom, 4.0]], 2.2, [[RoomType::Bathroom, 1.8], [RoomType::Hall, 2.2]],
                balcony: 'балкон',
            ),
            new Layout(
                '1-36', 4.4, [[RoomType::Kitchen, 2.5], [RoomType::Room, 3.5]],
                1.7, [[RoomType::Bathroom, 2.0], [RoomType::Hall, 4.0]],
                balcony: 'лоджия', balconyRoom: 1,
            ),
            new Layout(
                '1-44', 5.0, [[RoomType::Kitchen, 2.7], [RoomType::Room, 3.9]],
                1.8, [[RoomType::Bathroom, 2.2], [RoomType::Hall, 3.0], [RoomType::Wardrobe, 1.4]],
            ),
            new Layout(
                '2E-35', 4.2, [[RoomType::KitchenLiving, 3.6], [RoomType::Bedroom, 2.4]],
                1.6, [[RoomType::Bathroom, 1.9], [RoomType::Hall, 4.1]],
            ),
            new Layout(
                '2E-42', 4.5, [[RoomType::KitchenLiving, 3.8], [RoomType::Bedroom, 2.8]],
                1.9, [[RoomType::Bathroom, 2.0], [RoomType::Hall, 3.0], [RoomType::Wardrobe, 1.6]],
                balcony: 'лоджия',
            ),
            new Layout(
                '2E-55', 5.3, [[RoomType::KitchenLiving, 4.4], [RoomType::Bedroom, 3.2]],
                2.0, [[RoomType::Bathroom, 2.2], [RoomType::Hall, 3.6], [RoomType::Wardrobe, 1.8]],
            ),
            new Layout(
                '2-54', 4.5, [[RoomType::Room, 3.4], [RoomType::Kitchen, 2.6], [RoomType::Room, 2.6]],
                1.8, [[RoomType::Bath, 1.8], [RoomType::Toilet, 1.0], [RoomType::Hall, 5.8]],
                balcony: 'лоджия', balconyRoom: 1,
            ),
            new Layout(
                '3E-58', 4.4, [[RoomType::Bedroom, 2.8], [RoomType::KitchenLiving, 4.0], [RoomType::Bedroom, 2.6]],
                1.8, [[RoomType::Bathroom, 2.0], [RoomType::Hall, 5.6], [RoomType::Bathroom, 1.8]],
                balcony: 'лоджия', balconyRoom: 1,
            ),
            new Layout(
                '3E-78', 5.2, [[RoomType::Bedroom, 3.4], [RoomType::KitchenLiving, 4.6], [RoomType::Bedroom, 2.8]],
                2.0, [[RoomType::Bathroom, 2.2], [RoomType::Hall, 4.4], [RoomType::Bathroom, 2.0], [RoomType::Wardrobe, 2.2]],
            ),
            new Layout(
                '3-74', 4.6, [[RoomType::Room, 3.6], [RoomType::Kitchen, 2.8], [RoomType::Room, 2.6], [RoomType::Room, 2.4]],
                1.9, [[RoomType::Bathroom, 2.0], [RoomType::Hall, 6.8], [RoomType::Bathroom, 1.6], [RoomType::Wardrobe, 1.0]],
                balcony: 'лоджия',
            ),
            new Layout(
                '3-96', 5.4, [[RoomType::Room, 4.0], [RoomType::Kitchen, 2.6], [RoomType::Room, 3.2], [RoomType::Room, 2.8]],
                2.2, [[RoomType::Bathroom, 2.4], [RoomType::Hall, 6.6], [RoomType::Bathroom, 1.8], [RoomType::Laundry, 1.8]],
            ),
            new Layout(
                '4E-112', 5.6,
                [[RoomType::Bedroom, 3.6], [RoomType::Bedroom, 3.0], [RoomType::KitchenLiving, 5.0], [RoomType::Bedroom, 2.8]],
                2.2,
                [[RoomType::Bathroom, 2.4], [RoomType::Wardrobe, 2.4], [RoomType::Hall, 5.4], [RoomType::Bathroom, 2.2], [RoomType::Laundry, 2.0]],
            ),
        ];
    }

    /**
     * Coordinates are taken from OpenStreetMap; the buildings stand a few dozen meters apart.
     *
     * @return list<DemoComplex>
     */
    private function defineComplexes(): array
    {
        return [
            new DemoComplex(
                code: 'OK',
                name: 'Отрадный квартал',
                yandexBuildingId: '990101',
                pricePerSquareMeter: 320_000,
                demand: 1.0,
                tagline: 'Квартал у парка «Отрада», до метро «Владыкино» около десяти минут пешком: двор без машин и подземный паркинг.',
                buildings: [
                    new DemoBuilding(
                        number: 1,
                        yandexHouseId: '9901011',
                        address: 'Москва, Сигнальный проезд, 19к1',
                        latitude: 55.85421,
                        longitude: 37.59312,
                        floors: 17,
                        readyYear: 2026,
                        readyQuarter: 4,
                        state: 'built',
                        renovation: 'чистовая',
                        buildingType: 'монолит',
                        ceilingHeight: 2.8,
                        salesStart: '2025-02-03',
                        soldShare: 0.25,
                        floorPlate: [
                            new FloorPosition('3E-58', ['запад', 'юг'], 'во двор и на улицу', 'парк «Отрада»'),
                            new FloorPosition('2E-42', ['запад'], 'на улицу', 'парк «Отрада»'),
                            new FloorPosition('1-36', ['запад'], 'на улицу', 'парк «Отрада»'),
                            new FloorPosition('S-24', ['запад'], 'на улицу', 'парк «Отрада»'),
                            new FloorPosition('S-28', ['восток'], 'во двор'),
                            new FloorPosition('1-36', ['восток'], 'во двор'),
                            new FloorPosition('2E-42', ['восток'], 'во двор'),
                            new FloorPosition('2-54', ['восток', 'юг'], 'во двор и на улицу'),
                        ],
                    ),
                    new DemoBuilding(
                        number: 2,
                        yandexHouseId: '9901012',
                        address: 'Москва, Сигнальный проезд, 19к2',
                        latitude: 55.85372,
                        longitude: 37.59405,
                        floors: 22,
                        readyYear: 2027,
                        readyQuarter: 4,
                        state: 'unfinished',
                        renovation: 'whitebox',
                        buildingType: 'монолит',
                        ceilingHeight: 2.8,
                        salesStart: '2025-09-01',
                        soldShare: 0.1,
                        floorPlate: [
                            new FloorPosition('3-74', ['запад', 'север'], 'во двор и на улицу', 'парк «Отрада»'),
                            new FloorPosition('2E-42', ['запад'], 'на улицу', 'парк «Отрада»'),
                            new FloorPosition('1-36', ['запад'], 'на улицу', 'парк «Отрада»'),
                            new FloorPosition('S-28', ['запад'], 'на улицу', 'парк «Отрада»'),
                            new FloorPosition('S-24', ['восток'], 'во двор'),
                            new FloorPosition('1-36', ['восток'], 'во двор'),
                            new FloorPosition('2-54', ['восток'], 'во двор'),
                            new FloorPosition('3E-58', ['восток', 'север'], 'во двор и на улицу'),
                        ],
                    ),
                ],
            ),
            new DemoComplex(
                code: 'RT',
                name: 'Речная терраса',
                yandexBuildingId: '990102',
                pricePerSquareMeter: 590_000,
                demand: 0.6,
                tagline: 'Клубный квартал на берегу Москвы-реки у Крылатских холмов: видовые квартиры и подземный паркинг.',
                buildings: [
                    new DemoBuilding(
                        number: 1,
                        yandexHouseId: '9901021',
                        address: 'Москва, Крылатская улица, 2к1',
                        latitude: 55.75246,
                        longitude: 37.43398,
                        floors: 24,
                        readyYear: 2027,
                        readyQuarter: 2,
                        state: 'unfinished',
                        renovation: 'без отделки',
                        buildingType: 'монолит',
                        ceilingHeight: 3.1,
                        salesStart: '2025-04-14',
                        soldShare: 0.2,
                        floorPlate: [
                            new FloorPosition('4E-112', ['север', 'запад'], 'во двор и на улицу', 'Москву-реку'),
                            new FloorPosition('2E-55', ['север'], 'на улицу', 'Москву-реку'),
                            new FloorPosition('3E-78', ['север', 'восток'], 'во двор и на улицу', 'Москву-реку'),
                            new FloorPosition('2E-55', ['юг', 'восток'], 'во двор и на улицу'),
                            new FloorPosition('1-44', ['юг'], 'во двор'),
                            new FloorPosition('3-96', ['юг', 'запад'], 'во двор и на улицу', 'Крылатские холмы'),
                        ],
                    ),
                    new DemoBuilding(
                        number: 2,
                        yandexHouseId: '9901022',
                        address: 'Москва, Крылатская улица, 2к2',
                        latitude: 55.75204,
                        longitude: 37.43489,
                        floors: 28,
                        readyYear: 2028,
                        readyQuarter: 4,
                        state: 'unfinished',
                        renovation: 'чистовая',
                        buildingType: 'монолит',
                        ceilingHeight: 3.1,
                        salesStart: '2026-06-01',
                        soldShare: 0.05,
                        floorPlate: [
                            new FloorPosition('3E-78', ['север', 'запад'], 'во двор и на улицу', 'Москву-реку'),
                            new FloorPosition('2E-55', ['север'], 'на улицу', 'Москву-реку'),
                            new FloorPosition('4E-112', ['север', 'восток'], 'во двор и на улицу', 'Москву-реку'),
                            new FloorPosition('1-44', ['юг', 'восток'], 'во двор и на улицу'),
                            new FloorPosition('2E-55', ['юг'], 'во двор'),
                            new FloorPosition('3-96', ['юг', 'запад'], 'во двор и на улицу', 'Крылатские холмы'),
                        ],
                    ),
                ],
            ),
            new DemoComplex(
                code: 'TH',
                name: 'Тёплые холмы',
                yandexBuildingId: '990103',
                pricePerSquareMeter: 250_000,
                demand: 1.2,
                tagline: 'Квартал в Новой Москве у станции метро «Тютчевская»: парк, школа и детские сады в пешей доступности.',
                buildings: [
                    $this->warmHillsBuilding(
                        number: 1,
                        latitude: 55.61562,
                        longitude: 37.48176,
                        floors: 14,
                        readyYear: 2026,
                        readyQuarter: 2,
                        state: 'hand-over',
                        renovation: 'под ключ',
                        salesStart: '2024-05-20',
                        soldShare: 0.45,
                    ),
                    $this->warmHillsBuilding(
                        number: 2,
                        latitude: 55.61497,
                        longitude: 37.48249,
                        floors: 14,
                        readyYear: 2027,
                        readyQuarter: 3,
                        state: 'unfinished',
                        renovation: 'предчистовая',
                        salesStart: '2025-11-10',
                        soldShare: 0.15,
                    ),
                    $this->warmHillsBuilding(
                        number: 3,
                        latitude: 55.61431,
                        longitude: 37.4819,
                        floors: 12,
                        readyYear: 2028,
                        readyQuarter: 2,
                        state: 'unfinished',
                        renovation: 'черновая',
                        salesStart: '2026-10-26',
                        soldShare: 0.0,
                    ),
                ],
            ),
        ];
    }

    /**
     * Build a building of «Тёплые холмы»; all three follow one standard panel design.
     */
    private function warmHillsBuilding(
        int $number,
        float $latitude,
        float $longitude,
        int $floors,
        int $readyYear,
        int $readyQuarter,
        string $state,
        string $renovation,
        string $salesStart,
        float $soldShare,
    ): DemoBuilding {
        return new DemoBuilding(
            number: $number,
            yandexHouseId: '990103'.$number,
            address: "Москва, поселение Мосрентген, квартал 21А, 5к{$number}",
            latitude: $latitude,
            longitude: $longitude,
            floors: $floors,
            readyYear: $readyYear,
            readyQuarter: $readyQuarter,
            state: $state,
            renovation: $renovation,
            buildingType: 'панельный',
            ceilingHeight: 2.7,
            salesStart: $salesStart,
            soldShare: $soldShare,
            floorPlate: [
                new FloorPosition('3E-58', ['запад', 'юг'], 'во двор и на улицу', 'парк'),
                new FloorPosition('2E-35', ['юг'], 'во двор'),
                new FloorPosition('S-24', ['юг'], 'во двор'),
                new FloorPosition('1-36', ['юг'], 'во двор'),
                new FloorPosition('2E-35', ['юг', 'восток'], 'во двор и на улицу'),
                new FloorPosition('2-54', ['север', 'восток'], 'во двор и на улицу'),
                new FloorPosition('S-28', ['север'], 'на улицу'),
                new FloorPosition('2E-35', ['север'], 'на улицу'),
                new FloorPosition('1-36', ['север'], 'на улицу'),
                new FloorPosition('S-24', ['север', 'запад'], 'на улицу', 'парк'),
            ],
        );
    }
}
