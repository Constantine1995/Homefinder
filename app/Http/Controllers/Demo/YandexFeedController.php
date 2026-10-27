<?php

namespace App\Http\Controllers\Demo;

use App\Demo\Feed\DemoFeedClock;
use App\Demo\Feed\YandexFeedWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class YandexFeedController extends Controller
{
    /**
     * Serve the demo developer's feed as it looks on the current day of the sales simulation.
     */
    public function __invoke(DemoFeedClock $clock, YandexFeedWriter $writer): Response
    {
        return response($writer->write($clock->today()), headers: [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
