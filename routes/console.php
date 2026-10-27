<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('feeds:import-due')->everyMinute();

Schedule::command('geo:locate')->hourlyAt(30);

Schedule::command('search:embed')->hourlyAt(45);

// BBBike rebuilds the extract of the region every week.
Schedule::command('geo:import-pois')->weeklyOn(1, '05:00');
