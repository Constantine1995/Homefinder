<?php

namespace App\Services\SavedSearches;

use App\Enums\FlatEventField;
use App\Enums\FlatStatus;
use App\Enums\MessageRole;
use App\Jobs\DeliverMessage;
use App\Models\Flat;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Services\Catalog\FlatLabels;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells subscribed buyers about the flats that appeared or got cheaper since their last news: the agent writing to
 * the buyer by itself.
 *
 * Runs after each feed import, for the current developer. The message is written from the catalog, not by the
 * model: there is nothing in it to make up.
 */
class SavedSearchNotifier
{
    /**
     * A buyer gets news of a subscription no more often than this.
     */
    public const int HOURS_BETWEEN_NOTICES = 24;

    /**
     * Flats named in one message, the cheapest first.
     */
    public const int LISTED_FLATS = 5;

    /**
     * Send the news of every active subscription that has some and has not had news for a day.
     *
     * @return int The messages written.
     */
    public function notify(): int
    {
        return SavedSearch::query()
            ->with(['conversation', 'contact'])
            ->where('active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_notified_at')
                ->orWhere('last_notified_at', '<=', now()->subHours(self::HOURS_BETWEEN_NOTICES)))
            ->get()
            ->filter(fn (SavedSearch $search): bool => $this->tell($search))
            ->count();
    }

    /**
     * Write the news of the subscription to the buyer's open conversation, or to the test dialog it was made in.
     */
    private function tell(SavedSearch $search): bool
    {
        $flats = $this->news($search, $search->last_notified_at ?? $search->created_at);

        if ($flats->isEmpty()) {
            return false;
        }

        $message = DB::transaction(function () use ($search, $flats): Message {
            $conversation = $search->contact?->openConversation() ?? $search->conversation;
            $message = $conversation->messages()->create([
                'role' => MessageRole::Agent,
                'saved_search_id' => $search->id,
                'text' => $this->text($search, $flats),
            ]);
            // «расскажите про первую» after the news is about the flats of the news.
            $conversation->update([
                'dialog' => [...($conversation->dialog ?? []), 'shown_flat_ids' => $flats->pluck('id')->all()],
                'last_message_at' => $message->created_at,
            ]);
            $search->update(['last_notified_at' => now()]);

            return $message;
        });

        if ($search->contact_id !== null) {
            DeliverMessage::dispatch($message);
        }

        return true;
    }

    /**
     * Flats on sale that fit the subscription and since the moment went on sale or got cheaper, with the price they
     * had before as `previous_price` when it was higher.
     *
     * @return Collection<int, Flat>
     */
    private function news(SavedSearch $search, CarbonInterface $since): Collection
    {
        $priceBefore = DB::table('flat_events')
            ->select('old_value')
            ->whereColumn('flat_events.flat_id', 'flats.id')
            ->where('field', FlatEventField::Price)
            ->whereNotNull('old_value')
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(1);

        $query = Flat::query()
            ->select('flats.*')
            ->selectSub($priceBefore, 'previous_price')
            ->join('buildings', 'buildings.id', '=', 'flats.building_id')
            ->with('building.complex')
            ->whereExists(fn ($events) => $events
                ->from('flat_events')
                ->whereColumn('flat_events.flat_id', 'flats.id')
                ->where('flat_events.created_at', '>', $since)
                ->where(fn ($change) => $change
                    ->where(fn ($onSale) => $onSale
                        ->where('field', FlatEventField::Status)
                        ->whereRaw('new_value = to_jsonb(?::text)', [FlatStatus::Available->value]))
                    ->orWhere(fn ($cheaper) => $cheaper
                        ->where('field', FlatEventField::Price)
                        ->whereNotNull('old_value')
                        ->whereRaw('(new_value)::numeric < (old_value)::numeric'))))
            ->orderBy('flats.price')
            ->orderBy('flats.id')
            ->limit(self::LISTED_FLATS);

        // Only flats on sale now, whatever statuses the agent's conditions name.
        return $search->flatFilters()->apply($query)->where('flats.status', FlatStatus::Available)->get();
    }

    /**
     * @param  Collection<int, Flat>  $flats
     */
    private function text(SavedSearch $search, Collection $flats): string
    {
        $lines = $flats->map(function (Flat $flat): string {
            $previous = $flat->previous_price === null ? null : (int) json_decode((string) $flat->previous_price);
            $line = '— '.FlatLabels::name($flat).': '.FlatLabels::layout($flat).', '.FlatLabels::area($flat).', '.FlatLabels::price($flat->price);

            return $previous !== null && $previous > $flat->price ? "{$line}, было ".FlatLabels::price($previous) : $line;
        });

        return "По вашей подписке «{$search->description}» появились квартиры:\n"
            .$lines->implode("\n")
            ."\n\nНапишите, если хотите узнать о какой-то подробнее или записаться на просмотр.";
    }
}
