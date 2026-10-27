<?php

use App\Demo\Feed\DemoWorld;
use App\Demo\Feed\SalesSimulation;
use App\Demo\Feed\YandexFeedWriter;

/**
 * Write the demo feed for the given day and query it with the YRL namespace bound to the "y" prefix.
 */
function demoFeedXPath(int $day = 0): DOMXPath
{
    $document = new DOMDocument;
    $document->loadXML(app(YandexFeedWriter::class)->write($day));

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('y', 'http://webmaster.yandex.ru/schemas/feed/realty/2010-06');

    return $xpath;
}

/**
 * @return list<string>
 */
function demoFeedOfferIds(DOMXPath $xpath): array
{
    return array_map(
        fn (DOMElement $offer): string => $offer->getAttribute('internal-id'),
        iterator_to_array($xpath->query('/y:realty-feed/y:offer')),
    );
}

it('lists between 500 and 1000 flats on the first day', function () {
    $offerIds = demoFeedOfferIds(demoFeedXPath());

    expect(count($offerIds))->toBeBetween(500, 1000);
});

it('gives every offer its own internal id', function () {
    $offerIds = demoFeedOfferIds(demoFeedXPath());

    expect($offerIds)->toBe(array_values(array_unique($offerIds)));
});

it('writes every offer with the elements Yandex requires for new buildings', function () {
    $xpath = demoFeedXPath();
    $requiredElements = [
        'y:type', 'y:category', 'y:creation-date', 'y:location/y:address', 'y:sales-agent/y:phone',
        'y:deal-status', 'y:price/y:value', 'y:area/y:value', 'y:new-flat', 'y:building-name',
        'y:yandex-building-id', 'y:yandex-house-id', 'y:built-year', 'y:ready-quarter', 'y:building-state',
        'y:image[@tag="plan"]',
    ];
    $missingElements = [];

    foreach ($xpath->query('/y:realty-feed/y:offer') as $offer) {
        foreach ($requiredElements as $element) {
            if ($xpath->evaluate("count({$element})", $offer) === 0.0) {
                $missingElements[] = "{$offer->getAttribute('internal-id')}: {$element}";
            }
        }
    }

    expect($missingElements)->toBe([]);
});

it('uses only the values Yandex allows', function () {
    $xpath = demoFeedXPath();
    $oneOf = fn (string ...$values): string => '/^('.implode('|', array_map(fn (string $value): string => preg_quote($value, '/'), $values)).')$/u';
    $yes = $oneOf('да', 'true', '1', '+');
    $boolean = $oneOf('да', 'нет', 'true', 'false', '1', '0', '+', '-');
    $allowedValues = [
        'y:type' => $oneOf('продажа'),
        'y:property-type' => $oneOf('жилая'),
        'y:category' => $oneOf('дом', 'house', 'квартира', 'flat', 'таунхаус', 'townhouse'),
        'y:creation-date' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
        'y:sales-agent/y:phone' => '/^\+7\d{10}$/',
        'y:sales-agent/y:category' => $oneOf('агентство', 'agency', 'застройщик', 'developer'),
        'y:deal-status' => $oneOf('первичная продажа', 'продажа от застройщика', 'прямая продажа', 'переуступка', 'reassignment', '214ФЗ', 'ФЗ 214', '214 ФЗ', 'ФЗ214', '214', 'по 214 фз'),
        'y:price/y:value' => '/^\d+$/',
        'y:price/y:currency' => $oneOf('RUR', 'RUB', 'EUR', 'USD'),
        '*/y:unit' => $oneOf('кв. м', 'sq. m'),
        'y:renovation' => $oneOf('черновая', 'чистовая', 'под ключ', 'без отделки', 'предчистовая', 'whitebox'),
        'y:new-flat' => $yes,
        'y:studio' => $yes,
        'y:rooms' => '/^[1-9]\d*$/',
        'y:rooms-type' => $oneOf('смежные', 'раздельные'),
        'y:window-view' => $oneOf('во двор', 'на улицу', 'во двор и на улицу'),
        'y:bathroom-unit' => '/^(совмещенный|раздельный|\d+)$/u',
        'y:built-year' => '/^\d{4}$/',
        'y:ready-quarter' => $oneOf('1', '2', '3', '4'),
        'y:building-state' => $oneOf('built', 'hand-over', 'unfinished'),
        'y:building-type' => $oneOf('кирпичный', 'монолит', 'панельный'),
        'y:lift' => $boolean,
        'y:parking' => $boolean,
    ];
    $invalidValues = [];

    foreach ($xpath->query('/y:realty-feed/y:offer') as $offer) {
        foreach ($allowedValues as $element => $pattern) {
            foreach ($xpath->query($element, $offer) as $node) {
                if (preg_match($pattern, $node->textContent) !== 1) {
                    $invalidValues[] = "{$offer->getAttribute('internal-id')}: {$element} = «{$node->textContent}»";
                }
            }
        }
    }

    expect($invalidValues)->toBe([]);
});

it('gives studios no room count and other flats one room area per room', function () {
    $xpath = demoFeedXPath();
    $invalidOffers = [];

    foreach ($xpath->query('/y:realty-feed/y:offer') as $offer) {
        $isStudio = $xpath->evaluate('count(y:studio)', $offer) > 0;
        $rooms = $xpath->evaluate('number(y:rooms)', $offer);
        $roomSpaces = $xpath->evaluate('count(y:room-space)', $offer);

        $isValid = $isStudio ? is_nan($rooms) && $roomSpaces === 0.0 : $rooms === $roomSpaces;

        if (! $isValid) {
            $invalidOffers[] = $offer->getAttribute('internal-id');
        }
    }

    expect($invalidOffers)->toBe([]);
});

it('lists exactly the flats on sale that day', function () {
    $world = new DemoWorld;
    $simulation = new SalesSimulation($world);
    $flatsOnSale = [];

    foreach ($world->flats() as $flat) {
        if ($simulation->stateOf($flat, 7)->isListed()) {
            $flatsOnSale[] = $flat->id();
        }
    }

    $offerIds = demoFeedOfferIds(demoFeedXPath(7));

    expect($offerIds)->toBe($flatsOnSale);
});

it('writes the same feed for the same day whatever the real date', function () {
    $this->travelTo('2026-11-02 12:00:00');
    $feedWrittenInNovember = app(YandexFeedWriter::class)->write(3);

    $this->travelTo('2027-06-15 18:30:00');
    $feedWrittenInJune = app(YandexFeedWriter::class)->write(3);

    expect($feedWrittenInJune)->toBe($feedWrittenInNovember);
});
