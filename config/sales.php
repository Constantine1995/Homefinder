<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sales Office Hours
    |--------------------------------------------------------------------------
    |
    | When the developer's sales office answers buyers, in its region's time. The statistics count
    | the dialogs that buyers start outside these hours: the ones only the AI agent answers at once.
    | The demo developer's office works daily from 9:00 to 21:00, as its knowledge base says.
    |
    */

    'timezone' => env('SALES_TIMEZONE', 'Europe/Moscow'),

    'opens_at' => env('SALES_OPENS_AT', '09:00'),

    'closes_at' => env('SALES_CLOSES_AT', '21:00'),

];
