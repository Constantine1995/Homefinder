<?php

namespace App\Services\Dialogs;

use App\Enums\LeadPayment;
use App\Models\Conversation;
use App\Models\Lead;

/**
 * Keeps the conversation's lead card in step with what the agent has heard: the agent collects the fields in its
 * dialog state, turn after turn, and Laravel stores them where the cabinet and the statistics can read them.
 */
class LeadRecorder
{
    /**
     * The agent's intent for a buyer asking to come for a viewing.
     */
    public const string BOOKING_INTENT = 'booking';

    /**
     * Store the lead the agent's dialog state holds after a turn.
     *
     * A lead appears with the first thing the buyer tells about the purchase or the first request for a viewing;
     * a greeting makes none. The fields follow the state, which only ever adds what the buyer says or corrects it;
     * a request for a viewing is kept for good.
     *
     * @param  array<string, mixed>  $heard  The lead of the agent's dialog state (agent/src/homefinder/agent/state.py, Lead).
     */
    public function record(Conversation $conversation, array $heard, ?string $intent): ?Lead
    {
        $fields = [
            'budget' => $heard['budget'] ?? null,
            'payment' => LeadPayment::tryFrom((string) ($heard['payment'] ?? '')),
            'rooms' => array_values(array_map('intval', $heard['rooms'] ?? [])),
            'deadline_year' => $heard['deadline_year'] ?? null,
            'family' => $heard['family'] ?? null,
            'sells_own_flat' => $heard['sells_own_flat'] ?? null,
        ];
        $lead = $conversation->lead;
        $wantsViewing = $intent === self::BOOKING_INTENT || ($lead?->wants_viewing ?? false);

        if ($lead === null && ! $wantsViewing && array_filter($fields, fn (mixed $value): bool => $value !== null && $value !== []) === []) {
            return null;
        }

        $lead ??= $conversation->lead()->make(['contact_id' => $conversation->contact_id]);
        $lead->fill([...$fields, 'wants_viewing' => $wantsViewing])->save();

        return $lead;
    }
}
