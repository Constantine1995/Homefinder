<?php

namespace App\Demo\Feed;

/**
 * A flat's place on a typical floor of a building: its layout and where its windows look.
 */
readonly class FloorPosition
{
    /**
     * @param  string  $layout  Code of the layout built at this place.
     * @param  list<string>  $sides  Cardinal directions the windows face: "север", "юг", "запад", "восток".
     * @param  string  $windowView  Window view as Yandex names it: "во двор", "на улицу" or "во двор и на улицу".
     * @param  ?string  $landmark  What the windows overlook, in the accusative case: "парк «Отрада»", "Москву-реку".
     */
    public function __construct(
        public string $layout,
        public array $sides,
        public string $windowView,
        public ?string $landmark = null,
    ) {}
}
