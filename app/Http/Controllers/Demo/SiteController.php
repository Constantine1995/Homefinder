<?php

namespace App\Http\Controllers\Demo;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Complex;
use Illuminate\View\View;

class SiteController extends Controller
{
    /**
     * Show the demo developer's site with the chat widget, as a developer embeds it on a real one.
     */
    public function __invoke(): View
    {
        $channel = Channel::query()->where('type', ChannelType::Widget)->where('is_active', true)->oldest('id')->first();

        return view('demo.site', [
            'widgetKey' => $channel?->external_id,
            'complexes' => $channel === null ? collect() : $channel->tenant->run(
                fn () => Complex::query()->orderBy('name')->get(['name', 'address', 'description']),
            ),
        ]);
    }
}
