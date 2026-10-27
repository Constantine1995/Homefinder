<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;

/**
 * What the site widget needs before the visitor writes: what to say before the consent, and the visitor's token.
 *
 * The widget finds its channel by the public key the developer's site embeds it with; a switched-off widget, or a key
 * of another kind of channel, looks like no widget at all.
 */
class VisitorController extends Controller
{
    /**
     * Show what the widget says of the developer before the visitor agrees to the processing of personal data.
     */
    public function channel(Channel $channel): JsonResponse
    {
        $this->ensureWidget($channel);

        return response()->json([
            'name' => $channel->tenant->name,
            'introduction' => $channel->introduction(),
            'policy_url' => $channel->policyUrl(),
        ]);
    }

    /**
     * Start a visitor; the widget keeps the token in the browser and sends it as a bearer token from then on.
     */
    public function store(Channel $channel): JsonResponse
    {
        $this->ensureWidget($channel);

        return response()->json(['token' => Contact::startWidgetVisitor($channel)], 201);
    }

    private function ensureWidget(Channel $channel): void
    {
        abort_unless($channel->type === ChannelType::Widget && $channel->is_active, 404);
    }
}
