<?php

namespace App\Enums;

/**
 * Type of an OpenStreetMap element; together with the element ID it identifies an OSM object.
 */
enum OsmType: string
{
    case Node = 'node';
    case Way = 'way';
    case Relation = 'relation';
}
