<?php

namespace App\Http\Requests;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Models\Tenant;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\FlatFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CatalogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'complexes' => ['array'],
            'complexes.*' => ['integer', Rule::exists('complexes', 'id')->where('tenant_id', Tenant::currentId())],
            'rooms' => ['array'],
            'rooms.*' => ['integer', 'between:0,'.FlatFilters::MANY_ROOMS],
            'euro' => ['nullable', 'boolean'],
            'price_from' => ['nullable', 'integer', 'min:0'],
            'price_to' => ['nullable', 'integer', 'min:0'],
            'area_from' => ['nullable', 'numeric', 'min:0'],
            'area_to' => ['nullable', 'numeric', 'min:0'],
            'floor_from' => ['nullable', 'integer', 'between:-5,200'],
            'floor_to' => ['nullable', 'integer', 'between:-5,200'],
            'not_first_floor' => ['boolean'],
            'not_last_floor' => ['boolean'],
            'deadline_to' => ['nullable', 'date_format:Y-m-d'],
            'metro_walk_to' => ['nullable', 'integer', 'between:1,60'],
            'finishings' => ['array'],
            'finishings.*' => [Rule::enum(Finishing::class)],
            'statuses' => ['array'],
            'statuses.*' => [Rule::enum(FlatStatus::class)],
            'sort' => ['nullable', Rule::in(array_keys(CatalogQuery::SORTS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Get the filters the employee chose; without a status filter the catalog shows the flats on sale.
     */
    public function filters(): FlatFilters
    {
        $nullableInteger = fn (string $key): ?int => $this->filled($key) ? $this->integer($key) : null;
        $nullableFloat = fn (string $key): ?float => $this->filled($key) ? $this->float($key) : null;

        return new FlatFilters(
            complexIds: array_map('intval', $this->array('complexes')),
            rooms: array_map('intval', $this->array('rooms')),
            isEuro: $this->filled('euro') ? $this->boolean('euro') : null,
            priceFrom: $nullableInteger('price_from'),
            priceTo: $nullableInteger('price_to'),
            areaFrom: $nullableFloat('area_from'),
            areaTo: $nullableFloat('area_to'),
            floorFrom: $nullableInteger('floor_from'),
            floorTo: $nullableInteger('floor_to'),
            notFirstFloor: $this->boolean('not_first_floor'),
            notLastFloor: $this->boolean('not_last_floor'),
            deadlineTo: $this->filled('deadline_to') ? $this->string('deadline_to')->value() : null,
            metroWalkTo: $nullableInteger('metro_walk_to'),
            finishings: array_map(Finishing::from(...), $this->array('finishings')),
            statuses: $this->has('statuses') ? array_map(FlatStatus::from(...), $this->array('statuses')) : [FlatStatus::Available],
        );
    }

    public function sort(): string
    {
        return $this->string('sort', 'price')->value();
    }

    /**
     * @return 'asc'|'desc'
     */
    public function direction(): string
    {
        return $this->string('direction')->value() === 'desc' ? 'desc' : 'asc';
    }
}
