<?php

namespace App\Services\Dialogs;

use App\Enums\FlatStatus;
use App\Models\Flat;
use App\Models\Message;
use Illuminate\Support\Collection;

/**
 * The flats an agent's turn showed the buyer, which channels send as cards under the reply.
 */
class ShownFlats
{
    /**
     * Tools whose flats are shown: a search's results and the flat asked about.
     */
    public const array CARD_TOOLS = ['search_flats', 'get_flat'];

    /**
     * Get the flats the message's turn showed, in the order shown, still on sale.
     *
     * @return Collection<int, Flat>
     */
    public static function of(Message $message): Collection
    {
        return self::ofMessages(collect([$message]))->get($message->id, collect());
    }

    /**
     * Get the flats shown under each of the messages, by message ID, with one query of flats; messages that showed
     * no flats are left out. The messages must have their agent runs loaded.
     *
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, Collection<int, Flat>>
     */
    public static function ofMessages(Collection $messages): Collection
    {
        $flatIds = $messages
            ->mapWithKeys(fn (Message $message): array => [$message->id => self::shownIds($message)])
            ->filter();

        if ($flatIds->isEmpty()) {
            return collect();
        }

        $flats = Flat::query()
            ->with('building.complex')
            ->whereIn('id', $flatIds->flatten()->unique()->all())
            ->whereIn('status', [FlatStatus::Available, FlatStatus::Reserved])
            ->get()
            ->keyBy('id');

        return $flatIds
            ->map(fn (array $ids): Collection => collect($ids)->map(fn (int $id): ?Flat => $flats->get($id))->filter()->values())
            ->filter(fn (Collection $shown): bool => $shown->isNotEmpty());
    }

    /**
     * The IDs of the flats of the turn's last search or flat asked about that did not fail.
     *
     * @return list<int>
     */
    private static function shownIds(Message $message): array
    {
        $step = collect($message->agentRun?->steps ?? [])
            ->filter(fn (array $step): bool => in_array($step['tool'], self::CARD_TOOLS, true)
                && ! ($step['failed'] ?? false)
                && ($step['flat_ids'] ?? []) !== [])
            ->last();

        return $step['flat_ids'] ?? [];
    }
}
