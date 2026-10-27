<?php

namespace App\Http\Controllers\Demo;

use App\Demo\Feed\DemoWorld;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class FloorPlanController extends Controller
{
    /**
     * Draw the floor plan of one of the demo developer's layouts.
     */
    public function __invoke(DemoWorld $world, string $layout): Response
    {
        $plan = $world->layout($layout) ?? abort(404);

        return response()->view('demo.floor-plan', ['layout' => $plan], headers: [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
