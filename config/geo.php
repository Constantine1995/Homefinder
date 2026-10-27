<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenStreetMap Extract
    |--------------------------------------------------------------------------
    |
    | The region's OpenStreetMap extract in the PBF format, which the points of interest are
    | read from with osmium. BBBike rebuilds its Moscow extract every week. The data is
    | under the ODbL: pages that show it must credit «© OpenStreetMap contributors».
    |
    */

    'osm_extract_url' => env('GEO_OSM_EXTRACT_URL', 'https://download.bbbike.org/osm/bbbike/Moscow/Moscow.osm.pbf'),

    /*
    |--------------------------------------------------------------------------
    | Region
    |--------------------------------------------------------------------------
    |
    | Bounds of the region covered by the extract, as [west, south, east, north]. The geocoder
    | looks for addresses within them only: a complex outside them would have no surroundings.
    |
    */

    'region_bounds' => [37.322, 55.566, 37.881, 55.916],

    /*
    |--------------------------------------------------------------------------
    | Geocoder
    |--------------------------------------------------------------------------
    |
    | Nominatim finds the coordinates of buildings that came without them, as rows of a
    | spreadsheet often do. The public server allows one request a second from a program
    | that names itself in the User-Agent; a contact email is welcome there too.
    |
    */

    'nominatim' => [
        'url' => env('GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('GEO_NOMINATIM_USER_AGENT', 'homefinder/1.0 (pet project)'),
        'email' => env('GEO_NOMINATIM_EMAIL'),
        'seconds_between_requests' => 1,
        'addresses_per_run' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Surroundings
    |--------------------------------------------------------------------------
    |
    | Points of interest are linked to a complex within these distances, in meters: metro
    | stations farther away, since buyers ask how far the nearest one is even when it is
    | a bus ride away. Parks smaller than the minimum area, in square meters, are squares
    | rather than parks. Walking time is the straight distance lengthened by the detour
    | factor of city streets and walked at the given speed.
    |
    */

    'metro_radius' => 3000,

    'nearby_radius' => 1000,

    'park_min_area' => 10_000,

    'walk_detour_factor' => 1.3,

    'walk_meters_per_minute' => 80,

];
