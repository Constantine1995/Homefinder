<?php

namespace App\Http\Resources\Widget;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message of the visitor's dialog as the site widget shows it: what the agent's turn did stays in the cabinet,
 * the visitor sees the reply and the cards of the flats it showed.
 *
 * The flats come as the `shownFlats` relation set by the controller (App\Services\Dialogs\ShownFlats).
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role->value,
            'text' => $this->text,
            'created_at' => $this->created_at->toIso8601String(),
            'flats' => FlatCardResource::collection($this->whenLoaded('shownFlats')),
            'saved_search' => $this->savedSearch?->only('id', 'active'),
        ];
    }
}
