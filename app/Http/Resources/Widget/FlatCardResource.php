<?php

namespace App\Http\Resources\Widget;

use App\Enums\FlatStatus;
use App\Models\Flat;
use App\Services\Catalog\FlatLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A flat's card under the agent's reply in the site widget, as the Telegram card shows it; the flat must have its
 * building and complex loaded.
 *
 * @mixin Flat
 */
class FlatCardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{id: int, title: string, place: string, details: string, plan_url: string|null, reserved: bool}
     */
    public function toArray(Request $request): array
    {
        $building = $this->building;

        return [
            'id' => $this->id,
            'title' => FlatLabels::layout($this->resource).' · '.FlatLabels::area($this->resource).' · '.FlatLabels::price($this->price),
            'place' => collect([
                "ЖК «{$building->complex->name}»",
                $building->name,
                $this->number === null ? null : "кв. {$this->number}",
            ])->filter()->implode(', '),
            'details' => collect([
                FlatLabels::floor($this->resource, $building),
                $this->finishing?->label(),
                FlatLabels::deadline($building),
            ])->filter()->implode(' · '),
            'plan_url' => $this->plan_url,
            'reserved' => $this->status === FlatStatus::Reserved,
        ];
    }
}
