<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the product page: what the AI sales manager does, and the way into the demo cabinet.
     *
     * Its search box sends a buyer's message to the free-text search; a guest logs in first and comes back to it.
     */
    public function __invoke(): Response
    {
        return Inertia::render('home/Index', [
            'example' => SearchController::EXAMPLES[0],
        ]);
    }
}
