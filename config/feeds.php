<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Euro Layout Kitchen Area
    |--------------------------------------------------------------------------
    |
    | Feeds count rooms by the documents, where a kitchen-living room is a kitchen, so a
    | euro two-room flat comes as a one-room flat with a large kitchen. A flat whose kitchen
    | is at least this large, in square meters, is imported as a euro layout with one more room.
    |
    */

    'euro_kitchen_min_area' => (float) env('FEEDS_EURO_KITCHEN_MIN_AREA', 15),

    /*
    |--------------------------------------------------------------------------
    | Kept Uploads
    |--------------------------------------------------------------------------
    |
    | An uploaded spreadsheet is kept with its import on the local disk, so that a rejected
    | row of the report can be looked up in the file. This many latest uploads of each source
    | are kept; older files are deleted, while their import reports stay.
    |
    */

    'kept_uploads' => (int) env('FEEDS_KEPT_UPLOADS', 10),

];
