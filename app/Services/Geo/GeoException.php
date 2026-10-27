<?php

namespace App\Services\Geo;

use RuntimeException;

/**
 * A problem with an outside source of geodata rather than with the application: the OpenStreetMap
 * extract cannot be downloaded or filtered, holds no points of interest, or the geocoder fails.
 */
class GeoException extends RuntimeException {}
