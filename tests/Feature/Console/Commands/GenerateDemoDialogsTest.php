<?php

use App\Demo\Dialogs\DemoDialogs;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\AgentRun;
use App\Models\Channel;
use App\Models\Complex;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['name' => 'Демо-застройщик']);
    User::factory()->for($this->tenant)->create();
    foreach (['Отрадный квартал', 'Речная терраса', 'Тёплые холмы'] as $name) {
        Complex::factory()->for($this->tenant)->create(['name' => $name]);
    }
});

it('writes the demo developer\'s dialogs over the past days in a switched-off channel, telling no cabinet', function () {
    Event::fake([ConversationUpdated::class]);

    $this->artisan('demo-dialogs:generate', ['--tenant' => $this->tenant->id, '--count' => 60, '--days' => 10])
        ->expectsOutputToContain('Wrote 60 dialogs of Демо-застройщик over the last 10 days.')
        ->assertSuccessful();

    $channel = Channel::withoutGlobalScopes()->sole();
    $conversations = Conversation::withoutGlobalScopes()->get();
    expect($channel->external_id)->toBe(DemoDialogs::CHANNEL_ID)
        ->and($channel->is_active)->toBeFalse()
        ->and($conversations)->toHaveCount(60)
        ->and($conversations->every(fn (Conversation $conversation): bool => $conversation->tenant_id === $this->tenant->id
            && $conversation->created_at->greaterThan(now()->subDays(11))
            && $conversation->created_at->lessThan(now())))->toBeTrue()
        ->and($conversations->where('status', ConversationStatus::Human)->every(fn (Conversation $conversation): bool => $conversation->handoff_reason !== null))->toBeTrue()
        ->and(Lead::withoutGlobalScopes()->count())->toBeGreaterThan(0)
        ->and(AgentRun::withoutGlobalScopes()->whereNotNull('filters')->count())->toBeGreaterThan(0);
    Event::assertNotDispatched(ConversationUpdated::class);
});

it('replaces the dialogs it wrote before, and writes the same ones from the same seed', function () {
    $texts = function (): array {
        app(DemoDialogs::class)->generate($this->tenant, 20, 10, seed: 7);

        return Conversation::withoutGlobalScopes()->orderBy('id')->get()
            ->map(fn (Conversation $conversation): array => $conversation->messages()->orderBy('id')->pluck('text')->all())
            ->all();
    };

    expect($texts())->toBe($texts())
        ->and(Channel::withoutGlobalScopes()->count())->toBe(1)
        ->and(Conversation::withoutGlobalScopes()->count())->toBe(20);
});
