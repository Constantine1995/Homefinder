<?php

namespace App\Http\Controllers;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Http\Requests\CatalogRequest;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\FlatFilters;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    /**
     * Show the developer's flats by the filters, with the complexes on the map and the surroundings
     * of the complex when the catalog is narrowed down to one.
     */
    public function __invoke(CatalogRequest $request, CatalogQuery $catalog): Response
    {
        $filters = $request->filters();

        return Inertia::render('catalog/Index', [
            'filters' => [
                'complexes' => $filters->complexIds,
                'rooms' => $filters->rooms,
                'euro' => $filters->isEuro,
                'price_from' => $filters->priceFrom,
                'price_to' => $filters->priceTo,
                'area_from' => $filters->areaFrom,
                'area_to' => $filters->areaTo,
                'floor_from' => $filters->floorFrom,
                'floor_to' => $filters->floorTo,
                'not_first_floor' => $filters->notFirstFloor,
                'not_last_floor' => $filters->notLastFloor,
                'deadline_to' => $filters->deadlineTo,
                'metro_walk_to' => $filters->metroWalkTo,
                'finishings' => array_map(fn (Finishing $finishing): string => $finishing->value, $filters->finishings),
                'statuses' => array_map(fn (FlatStatus $status): string => $status->value, $filters->statuses),
            ],
            'sort' => ['field' => $request->sort(), 'direction' => $request->direction()],
            'flats' => fn () => $catalog->flats($filters, $request->sort(), $request->direction()),
            'surroundings' => fn (): array => count($filters->complexIds) === 1 ? $catalog->surroundings($filters->complexIds[0]) : [],
            'complexes' => fn (): array => $catalog->complexes(),
            'options' => fn (): array => [
                'finishings' => array_map(fn (Finishing $finishing): array => ['value' => $finishing->value, 'label' => $finishing->label()], Finishing::cases()),
                'statuses' => array_map(fn (FlatStatus $status): array => ['value' => $status->value, 'label' => $status->label()], FlatStatus::cases()),
                'deadlines' => $catalog->deadlines(),
                'many_rooms' => FlatFilters::MANY_ROOMS,
            ],
        ]);
    }
}
