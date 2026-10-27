<?php

namespace App\Services\Geo;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * The region's OpenStreetMap extract: downloads it and turns it with osmium into a GeoJSON text sequence
 * of the objects that may be points of interest, one feature per line.
 *
 * osmium keeps the nodes the areas are built of, so the sequence also holds untagged and unrelated
 * objects: telling points of interest apart is left to the importer.
 */
class OsmExtract
{
    private const int DOWNLOAD_TIMEOUT_SECONDS = 600;

    private const int OSMIUM_TIMEOUT_SECONDS = 600;

    /**
     * osmium tag filters for the objects of every point-of-interest type.
     *
     * @var list<string>
     */
    private const array TAG_FILTERS = [
        'n/railway=station',
        'nwr/amenity=school,kindergarten',
        'nwr/leisure=park',
        'nwr/shop=supermarket,convenience,mall',
    ];

    public function __construct(
        #[Config('geo.osm_extract_url')] private string $url,
    ) {}

    /**
     * Download the extract into the directory; it weighs tens of megabytes, so it goes to a file.
     *
     * @return string The path of the extract.
     *
     * @throws GeoException
     */
    public function download(string $directory): string
    {
        $path = "{$directory}/extract.osm.pbf";

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->sink($path)->get($this->url);
        } catch (ConnectionException $exception) {
            throw new GeoException("The OpenStreetMap extract could not be downloaded: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->failed()) {
            throw new GeoException("The OpenStreetMap extract server responded with HTTP {$response->status()}.");
        }

        return $path;
    }

    /**
     * Filter the extract down to the objects that may be points of interest and export them with their geometry.
     *
     * @return string The path of the GeoJSON text sequence.
     *
     * @throws GeoException when osmium fails, as it does on a file that is not an extract
     */
    public function export(string $extractPath, string $directory): string
    {
        $filteredPath = "{$directory}/pois.osm.pbf";
        $exportPath = "{$directory}/pois.geojsonseq";

        $this->osmium(['tags-filter', '--overwrite', '--output', $filteredPath, $extractPath, ...self::TAG_FILTERS]);
        $this->osmium([
            'export', '--overwrite', '--output', $exportPath,
            '--output-format', 'geojsonseq',
            '--geometry-types', 'point,polygon',
            '--add-unique-id', 'type_id',
            $filteredPath,
        ]);

        return $exportPath;
    }

    /**
     * @param  list<string>  $arguments
     *
     * @throws GeoException
     */
    private function osmium(array $arguments): void
    {
        $result = Process::timeout(self::OSMIUM_TIMEOUT_SECONDS)->run(['osmium', ...$arguments]);

        if ($result->failed()) {
            throw new GeoException("osmium {$arguments[0]} failed: ".trim($result->errorOutput() ?: $result->output()));
        }
    }
}
