<?php

namespace App\Services\Feeds;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use Carbon\CarbonImmutable;
use DOMDocument;
use Generator;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Str;
use SimpleXMLElement;
use XMLReader;

/**
 * Reads a feed in the Yandex Realty format for new buildings (YRL) one offer at a time,
 * so even a feed of tens of thousands of flats is never loaded whole.
 *
 * The format has no sales status: developers list only the flats on sale, so every flat read is available.
 * A value that is present but malformed rejects its offer: written as it is, it could fail the whole import.
 *
 * @see https://yandex.ru/support/realty/ru/feed/requirements-sale-new
 */
class YandexFeedReader implements FeedReader
{
    /**
     * Finishing levels by their Yandex names.
     *
     * @var array<string, Finishing>
     */
    private const array FINISHINGS = [
        'без отделки' => Finishing::Rough,
        'черновая' => Finishing::Rough,
        'предчистовая' => Finishing::WhiteBox,
        'whitebox' => Finishing::WhiteBox,
        'чистовая' => Finishing::Finished,
        'под ключ' => Finishing::Furnished,
    ];

    /**
     * Elements kept as the flat's features for soft-wish search, by feature name.
     *
     * @var array<string, string>
     */
    private const array FEATURES = [
        'window_view' => 'window-view',
        'balcony' => 'balcony',
        'bathroom_unit' => 'bathroom-unit',
        'rooms_type' => 'rooms-type',
    ];

    /**
     * Longest text a string column of the catalog holds.
     */
    private const int MAX_STRING_LENGTH = 255;

    private const int MAX_URL_LENGTH = 2048;

    public function __construct(
        #[Config('feeds.euro_kitchen_min_area')] private float $euroKitchenMinArea,
    ) {}

    /**
     * Read the offers of the feed file, keyed by their position in the feed starting from 1.
     *
     * @return Generator<int, FeedOffer|InvalidOffer>
     *
     * @throws FeedException when the file is not a well-formed YRL feed
     */
    public function read(string $path): Generator
    {
        $usedInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = new XMLReader;

        try {
            if (! $xml->open($path)) {
                throw new FeedException('The feed file could not be opened.');
            }

            $this->moveToRoot($xml);
            $position = 0;
            $hasNode = $xml->read();

            // Step over the root's children without descending into them; the root's end tag is at depth 0.
            while ($hasNode && $xml->depth > 0) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'offer') {
                    $position++;

                    yield $position => $this->parse($this->expand($xml, $position));
                }

                $hasNode = $xml->next();
            }

            $this->failOnXmlError();
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($usedInternalErrors);
        }
    }

    /**
     * Move to the root element and make sure it is the root of a YRL feed.
     *
     * @throws FeedException
     */
    private function moveToRoot(XMLReader $xml): void
    {
        while ($xml->read()) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($xml->localName !== 'realty-feed') {
                throw new FeedException("The feed's root element is <{$xml->localName}>, not <realty-feed>.");
            }

            return;
        }

        $this->failOnXmlError();

        throw new FeedException('The feed has no root element.');
    }

    /**
     * @throws FeedException
     */
    private function expand(XMLReader $xml, int $position): SimpleXMLElement
    {
        // On a malformed offer expand() warns on top of the libxml error, which tells more.
        $node = @$xml->expand(new DOMDocument);

        if ($node === false) {
            $this->failOnXmlError(" at offer {$position}");

            throw new FeedException("Offer {$position} of the feed could not be read.");
        }

        return simplexml_import_dom($node);
    }

    /**
     * @param  string  $place  Where the error was met, such as " at offer 12".
     *
     * @throws FeedException when the parser has met an error, as a truncated or malformed feed makes it do
     */
    private function failOnXmlError(string $place = ''): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level !== LIBXML_ERR_WARNING) {
                throw new FeedException(sprintf('The feed is not well-formed XML%s: %s on line %d.', $place, trim($error->message), $error->line));
            }
        }
    }

    private function parse(SimpleXMLElement $offer): FeedOffer|InvalidOffer
    {
        $flatId = trim((string) $offer['internal-id']);

        try {
            return $this->offer($offer, $flatId);
        } catch (InvalidOfferException $exception) {
            return new InvalidOffer($flatId === '' ? null : $flatId, $exception->getMessage());
        }
    }

    /**
     * @throws InvalidOfferException
     */
    private function offer(SimpleXMLElement $offer, string $flatId): FeedOffer
    {
        if ($flatId === '') {
            throw new InvalidOfferException('The offer has no internal-id attribute.');
        }

        $type = $this->requiredText($offer, 'type');

        if (mb_strtolower($type) !== 'продажа') {
            throw new InvalidOfferException("Only offers for sale are imported, but <type> is «{$type}».");
        }

        $category = $this->requiredText($offer, 'category');

        if (! in_array(mb_strtolower($category), ['квартира', 'flat'], true)) {
            throw new InvalidOfferException("Only flats are imported, but <category> is «{$category}».");
        }

        $complexId = $this->short($this->requiredText($offer, 'yandex-building-id'), 'yandex-building-id');
        $buildingId = $this->short($this->requiredText($offer, 'yandex-house-id'), 'yandex-house-id');
        $area = $this->number($offer, 'area/value', 1, 10_000) ?? throw $this->missing('area/value');
        $kitchenArea = $this->number($offer, 'kitchen-space/value', 1, 1_000);
        [$rooms, $isEuro] = $this->rooms($offer, $kitchenArea);

        return new FeedOffer(
            complex: [
                'external_id' => $complexId,
                'name' => $this->short($this->text($offer, 'building-name'), 'building-name') ?? "ЖК {$complexId}",
            ],
            building: [
                'external_id' => $buildingId,
                'name' => $this->short($this->text($offer, 'building-section'), 'building-section') ?? "Корпус {$buildingId}",
                'address' => $this->short($this->text($offer, 'location/address'), 'location/address'),
                'latitude' => $this->number($offer, 'location/latitude', -90, 90),
                'longitude' => $this->number($offer, 'location/longitude', -180, 180),
                'floors' => $this->integer($offer, 'floors-total', 1, 200),
                'deadline' => $this->deadline($offer),
            ],
            flat: [
                'external_id' => $this->short($flatId, 'internal-id'),
                'number' => $this->short($this->text($offer, 'location/apartment'), 'location/apartment'),
                'floor' => $this->integer($offer, 'floor', -5, 200) ?? throw $this->missing('floor'),
                'rooms' => $rooms,
                'is_euro' => $isEuro,
                'area' => $area,
                'kitchen_area' => $kitchenArea,
                'finishing' => self::FINISHINGS[mb_strtolower((string) $this->text($offer, 'renovation'))] ?? null,
                'price' => $this->price($offer, $area),
                'status' => FlatStatus::Available,
                'plan_url' => $this->planUrl($offer),
                'description' => $this->text($offer, 'description'),
                'features' => $this->features($offer),
            ],
        );
    }

    /**
     * Count the rooms as buyers do. By the documents a kitchen-living room is a kitchen, so a euro
     * two-room flat comes as a one-room flat with a large kitchen, and the kitchen is counted as a room.
     *
     * @return array{int, bool} The number of rooms, 0 for studios, and whether the layout is a euro one.
     *
     * @throws InvalidOfferException
     */
    private function rooms(SimpleXMLElement $offer, ?float $kitchenArea): array
    {
        if ($this->boolean($offer, 'studio')) {
            return [0, false];
        }

        $rooms = $this->integer($offer, 'rooms', 1, 20);

        if ($rooms === null) {
            if ($this->boolean($offer, 'open-plan')) {
                return [0, false];
            }

            throw new InvalidOfferException('The offer has neither <rooms> nor <studio>.');
        }

        $isEuro = $kitchenArea !== null && $kitchenArea >= $this->euroKitchenMinArea;

        return [$isEuro ? $rooms + 1 : $rooms, $isEuro];
    }

    /**
     * Get the price of the whole flat in rubles; a feed may give the price per square meter instead.
     *
     * @throws InvalidOfferException
     */
    private function price(SimpleXMLElement $offer, float $area): int
    {
        $price = $this->number($offer, 'price/value', 1, 1_000_000_000_000) ?? throw $this->missing('price/value');
        $currency = $this->text($offer, 'price/currency') ?? 'RUB';

        if (! in_array(mb_strtoupper($currency), ['RUB', 'RUR'], true)) {
            throw new InvalidOfferException("Only prices in rubles are imported, but <price/currency> is «{$currency}».");
        }

        $unit = $this->text($offer, 'price/unit');

        if ($unit !== null) {
            if (! in_array(str_replace([' ', '.'], '', mb_strtolower($unit)), ['квм', 'sqm'], true)) {
                throw new InvalidOfferException("Only prices per square meter are imported, but <price/unit> is «{$unit}».");
            }

            $price *= $area;
        }

        return (int) round($price);
    }

    /**
     * Get the last day of the quarter the building is completed in.
     *
     * @throws InvalidOfferException
     */
    private function deadline(SimpleXMLElement $offer): ?string
    {
        $year = $this->integer($offer, 'built-year', 1900, 2100);

        if ($year === null) {
            return null;
        }

        $quarter = $this->integer($offer, 'ready-quarter', 1, 4) ?? 4;

        return CarbonImmutable::create($year, $quarter * 3)->endOfMonth()->toDateString();
    }

    /**
     * Get the floor plan; other images of an offer are photos.
     *
     * @throws InvalidOfferException
     */
    private function planUrl(SimpleXMLElement $offer): ?string
    {
        foreach ($offer->image as $image) {
            if (mb_strtolower(trim((string) $image['tag'])) !== 'plan') {
                continue;
            }

            $url = trim((string) $image);

            if (! Str::startsWith(mb_strtolower($url), ['https://', 'http://']) || mb_strlen($url) > self::MAX_URL_LENGTH) {
                throw new InvalidOfferException('The floor plan is not an http(s) link of up to 2048 characters: «'.Str::limit($url, 100).'».');
            }

            return $url;
        }

        return null;
    }

    /**
     * @return array<string, string|float|bool>
     *
     * @throws InvalidOfferException
     */
    private function features(SimpleXMLElement $offer): array
    {
        $features = [];

        foreach (self::FEATURES as $feature => $path) {
            $value = $this->text($offer, $path);

            if ($value !== null) {
                $features[$feature] = $value;
            }
        }

        $ceilingHeight = $this->number($offer, 'ceiling-height', 1, 10);

        if ($ceilingHeight !== null) {
            $features['ceiling_height'] = $ceilingHeight;
        }

        if ($this->boolean($offer, 'open-plan')) {
            $features['open_plan'] = true;
        }

        return $features;
    }

    /**
     * Get the trimmed text of the element at the path, such as "price/value"; a missing or empty element gives null.
     */
    private function text(SimpleXMLElement $offer, string $path): ?string
    {
        $element = $offer;

        foreach (explode('/', $path) as $name) {
            $element = $element->{$name}[0] ?? null;

            if ($element === null) {
                return null;
            }
        }

        $text = trim((string) $element);

        return $text === '' ? null : $text;
    }

    /**
     * @throws InvalidOfferException
     */
    private function requiredText(SimpleXMLElement $offer, string $path): string
    {
        return $this->text($offer, $path) ?? throw $this->missing($path);
    }

    /**
     * Make sure the text fits a string column.
     *
     * @throws InvalidOfferException
     */
    private function short(?string $text, string $path): ?string
    {
        if ($text !== null && mb_strlen($text) > self::MAX_STRING_LENGTH) {
            throw new InvalidOfferException("<{$path}> is longer than ".self::MAX_STRING_LENGTH.' characters.');
        }

        return $text;
    }

    /**
     * Get the number in the element, accepting a decimal comma and spaces between digits; a missing element gives null.
     *
     * @throws InvalidOfferException when the element holds something other than a number within the range
     */
    private function number(SimpleXMLElement $offer, string $path, int $min, int $max): ?float
    {
        $text = $this->text($offer, $path);

        if ($text === null) {
            return null;
        }

        $number = str_replace([',', ' ', "\u{A0}"], ['.', '', ''], $text);

        if (! is_numeric($number)) {
            throw new InvalidOfferException("<{$path}> is not a number: «{$text}».");
        }

        if ($number < $min || $number > $max) {
            throw new InvalidOfferException("<{$path}> must be from {$min} to {$max}, but is «{$text}».");
        }

        return (float) $number;
    }

    /**
     * @throws InvalidOfferException
     */
    private function integer(SimpleXMLElement $offer, string $path, int $min, int $max): ?int
    {
        $number = $this->number($offer, $path, $min, $max);

        if ($number !== null && $number !== floor($number)) {
            throw new InvalidOfferException("<{$path}> is not a whole number: «{$this->text($offer, $path)}».");
        }

        return $number === null ? null : (int) $number;
    }

    private function boolean(SimpleXMLElement $offer, string $path): bool
    {
        return in_array(mb_strtolower((string) $this->text($offer, $path)), ['да', 'true', '1', '+'], true);
    }

    private function missing(string $path): InvalidOfferException
    {
        return new InvalidOfferException("The offer has no <{$path}>.");
    }
}
