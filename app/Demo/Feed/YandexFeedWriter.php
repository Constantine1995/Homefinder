<?php

namespace App\Demo\Feed;

use Illuminate\Container\Attributes\Config;
use XMLWriter;

/**
 * Writes the demo developer's feed in the Yandex Realty format for new buildings (YRL).
 *
 * The format has no sales status: like a real developer, the feed lists only the flats on sale,
 * and a reserved or sold flat simply disappears from it.
 *
 * @see https://yandex.ru/support/realty/ru/feed/requirements-sale-new
 */
class YandexFeedWriter
{
    private const string NAMESPACE = 'http://webmaster.yandex.ru/schemas/feed/realty/2010-06';

    public function __construct(
        private DemoWorld $world,
        private SalesSimulation $simulation,
        #[Config('app.url')] private string $appUrl,
    ) {}

    /**
     * Write the feed as the developer publishes it on the given day of the simulation.
     */
    public function write(int $day): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('realty-feed');
        $xml->writeAttribute('xmlns', self::NAMESPACE);
        $xml->writeElement('generation-date', $this->world->dateOf($day)->toIso8601String());

        foreach ($this->world->flats() as $flat) {
            $state = $this->simulation->stateOf($flat, $day);

            if ($state->isListed()) {
                $this->writeOffer($xml, $flat, $state);
            }
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function writeOffer(XMLWriter $xml, DemoFlat $flat, FlatState $state): void
    {
        $complex = $flat->complex;
        $building = $flat->building;
        $layout = $flat->layout;

        $xml->startElement('offer');
        $xml->writeAttribute('internal-id', $flat->id());
        $xml->writeElement('type', 'продажа');
        $xml->writeElement('property-type', 'жилая');
        $xml->writeElement('category', 'квартира');
        $xml->writeElement('creation-date', $this->world->dateOf($state->releaseDay)->toIso8601String());

        $xml->startElement('location');
        $xml->writeElement('address', $building->address);
        $xml->writeElement('apartment', (string) $flat->number);
        $xml->writeElement('latitude', number_format($building->latitude, 6, '.', ''));
        $xml->writeElement('longitude', number_format($building->longitude, 6, '.', ''));
        $xml->endElement();

        $xml->startElement('sales-agent');
        $xml->writeElement('phone', DemoWorld::SELLER_PHONE);
        $xml->writeElement('organization', DemoWorld::SELLER_NAME);
        $xml->writeElement('category', 'developer');
        $xml->endElement();

        $xml->writeElement('deal-status', 'первичная продажа');

        $xml->startElement('price');
        $xml->writeElement('value', (string) $state->price);
        $xml->writeElement('currency', 'RUB');
        $xml->endElement();

        $this->writeSpace($xml, 'area', $layout->area());
        $this->writeSpace($xml, 'living-space', $layout->livingSpace());

        if ($layout->kitchenSpace() !== null) {
            $this->writeSpace($xml, 'kitchen-space', $layout->kitchenSpace());
        }

        foreach ($layout->roomSpaces() as $roomSpace) {
            $this->writeSpace($xml, 'room-space', $roomSpace);
        }

        $xml->startElement('image');
        $xml->writeAttribute('tag', 'plan');
        $xml->text($this->planUrl($layout));
        $xml->endElement();

        $xml->writeElement('renovation', $building->renovation);
        $xml->writeElement('description', $flat->description());
        $xml->writeElement('new-flat', '1');

        if ($layout->isStudio()) {
            $xml->writeElement('studio', '1');
        } else {
            $xml->writeElement('rooms', (string) $layout->roomCount());
        }

        if ($layout->roomCount() > 1) {
            $xml->writeElement('rooms-type', 'раздельные');
        }

        $xml->writeElement('floor', (string) $flat->floor);
        $xml->writeElement('floors-total', (string) $building->floors);

        if ($layout->balcony !== null) {
            $xml->writeElement('balcony', $layout->balcony);
        }

        $xml->writeElement('window-view', $flat->position->windowView);
        $xml->writeElement('bathroom-unit', $layout->bathroomUnit());
        $xml->writeElement('ceiling-height', number_format($building->ceilingHeight, 2, '.', ''));
        $xml->writeElement('building-name', $complex->name);
        $xml->writeElement('yandex-building-id', $complex->yandexBuildingId);
        $xml->writeElement('yandex-house-id', $building->yandexHouseId);
        $xml->writeElement('building-section', $building->section());
        $xml->writeElement('building-state', $building->state);
        $xml->writeElement('built-year', (string) $building->readyYear);
        $xml->writeElement('ready-quarter', (string) $building->readyQuarter);
        $xml->writeElement('building-type', $building->buildingType);
        $xml->writeElement('lift', '1');
        $xml->writeElement('parking', '1');
        $xml->endElement();
    }

    private function writeSpace(XMLWriter $xml, string $element, float $squareMeters): void
    {
        $xml->startElement($element);
        $xml->writeElement('value', number_format($squareMeters, 1, '.', ''));
        $xml->writeElement('unit', 'кв. м');
        $xml->endElement();
    }

    /**
     * Link the plan to the application URL rather than to the host the feed was requested from:
     * the importer fetches the feed from inside Docker, but buyers open the plans in a browser.
     */
    private function planUrl(Layout $layout): string
    {
        return rtrim($this->appUrl, '/').route('demo.plans.show', ['layout' => $layout->code], absolute: false);
    }
}
