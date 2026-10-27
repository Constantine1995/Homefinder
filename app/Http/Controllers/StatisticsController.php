<?php

namespace App\Http\Controllers;

use App\Http\Requests\StatisticsRequest;
use App\Models\Complex;
use App\Services\Statistics\SalesStatistics;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    /**
     * Show the sales head the dialogs, leads and demand of the chosen period.
     */
    public function __invoke(StatisticsRequest $request, SalesStatistics $statistics): Response
    {
        return Inertia::render('statistics/Index', [
            'days' => $request->days(),
            'periods' => StatisticsRequest::PERIODS,
            'office_hours' => [
                'opens_at' => config('sales.opens_at'),
                'closes_at' => config('sales.closes_at'),
            ],
            'complexes' => Complex::query()->orderBy('name')->pluck('name', 'id')->all(),
            'statistics' => $statistics->report($request->since()),
        ]);
    }
}
