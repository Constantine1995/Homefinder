<?php

namespace App\Enums;

/**
 * Format of a source the developer's flats are imported from.
 */
enum FeedType: string
{
    /**
     * XML feed in the Yandex Realty format.
     */
    case Yandex = 'yandex';

    /**
     * XML feed in the CIAN format.
     */
    case Cian = 'cian';

    /**
     * XML feed in the Avito format.
     */
    case Avito = 'avito';

    /**
     * Availability spreadsheet (CSV or Excel) uploaded through the cabinet.
     */
    case Csv = 'csv';
}
