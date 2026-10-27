<?php

namespace App\Services\Geo;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Finds the coordinates of an address within the region with Nominatim, the geocoder of OpenStreetMap.
 *
 * Requests are spaced out as the usage policy of the public server asks.
 *
 * @see https://operations.osmfoundation.org/policies/nominatim/
 */
class NominatimGeocoder
{
    private const int TIMEOUT_SECONDS = 10;

    private bool $hasRequested = false;

    /**
     * @param  array{float, float, float, float}  $regionBounds  West, south, east and north bounds of the region.
     */
    public function __construct(
        #[Config('geo.nominatim.url')] private string $url,
        #[Config('geo.nominatim.user_agent')] private string $userAgent,
        #[Config('geo.nominatim.email')] private ?string $email,
        #[Config('geo.nominatim.seconds_between_requests')] private int $secondsBetweenRequests,
        #[Config('geo.region_bounds')] private array $regionBounds,
    ) {}

    /**
     * @return array{latitude: float, longitude: float}|null The coordinates, or null if the region has no such address.
     *
     * @throws GeoException when the geocoder is unreachable or fails
     */
    public function geocode(string $address): ?array
    {
        if ($this->hasRequested) {
            Sleep::for($this->secondsBetweenRequests)->seconds();
        }

        $this->hasRequested = true;

        try {
            $response = Http::withUserAgent($this->userAgent)
                ->timeout(self::TIMEOUT_SECONDS)
                ->get(rtrim($this->url, '/').'/search', array_filter([
                    'q' => $address,
                    'format' => 'jsonv2',
                    'limit' => 1,
                    'countrycodes' => 'ru',
                    'accept-language' => 'ru',
                    'viewbox' => implode(',', $this->regionBounds),
                    'bounded' => 1,
                    'email' => $this->email,
                ]));
        } catch (ConnectionException $exception) {
            throw new GeoException("The geocoder could not be reached: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->failed()) {
            throw new GeoException("The geocoder responded with HTTP {$response->status()}.");
        }

        $place = $response->json(0);

        if (! is_numeric($place['lat'] ?? null) || ! is_numeric($place['lon'] ?? null)) {
            return null;
        }

        return ['latitude' => (float) $place['lat'], 'longitude' => (float) $place['lon']];
    }
}
