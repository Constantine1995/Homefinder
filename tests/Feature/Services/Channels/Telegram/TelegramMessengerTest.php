<?php

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Models\AgentRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flat;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Services\Channels\Telegram\TelegramMessenger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Fake the Bot API and a developer's site serving an SVG plan; a sent photo is kept under file ID «plan-file».
 */
function fakeTelegramWithPlans(int $planStatus = 200): void
{
    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => [
            'message_id' => 100,
            'photo' => [['file_id' => 'plan-thumb'], ['file_id' => 'plan-file']],
        ]]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
        'developer.test/plans/*' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"/>', $planStatus, ['Content-Type' => 'image/svg+xml']),
    ]);
}

/**
 * The agent's reply in a Telegram conversation, written by a turn whose search showed the given flats.
 *
 * @param  list<Flat>  $flats
 */
function agentReply(Contact $contact, string $text = 'Подобрал варианты.', array $flats = []): Message
{
    $conversation = Conversation::factory()->withContact($contact)->create();
    $message = Message::factory()->for($conversation)->fromAgent($text)->create();
    AgentRun::factory()->for($conversation)->create([
        'message_id' => $message->id,
        'steps' => [['tool' => 'search_flats', 'args' => [], 'summary' => '', 'data' => [], 'flat_ids' => array_map(fn (Flat $flat): int => $flat->id, $flats)]],
    ]);

    return $message;
}

function deliver(Message $message): void
{
    $message->tenant->run(fn () => app(TelegramMessenger::class)->deliver($message->fresh()));
}

it('sends the reply as HTML, with the model\'s bold made bold and the rest escaped', function () {
    fakeTelegramWithPlans();
    $this->freezeSecond();
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $message = agentReply($contact, "**кв. 12** — 8 762 000 ₽  \nдвор <тихий> & зелёный");

    deliver($message);

    expect(telegramCalls('sendMessage'))->toBe([[
        'chat_id' => '5001',
        'text' => "<b>кв. 12</b> — 8 762 000 ₽\nдвор &lt;тихий&gt; &amp; зелёный",
        'parse_mode' => 'HTML',
        'link_preview_options' => ['is_disabled' => true],
    ]])->and($message->fresh()->delivered_at->equalTo(now()))->toBeTrue();
});

it('sends the flats the turn found as cards with the plan drawn into a picture', function () {
    fakeTelegramWithPlans();
    Process::fake(['*rsvg-convert*' => Process::result("PNG-BYTES\n")]);
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel);
    $flat->update(['finishing' => Finishing::Finished, 'floor' => 5, 'plan_url' => 'https://developer.test/plans/2-54.svg']);

    deliver(agentReply($contact, flats: [$flat]));

    $photo = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
    $parts = collect($photo->data())->pluck('contents', 'name');
    expect($parts['photo'])->toBe("PNG-BYTES\n")
        ->and($parts['caption'])->toBe("<b>2-комн. · 54,1 м² · 8 762 000 ₽</b>\nЖК «Отрадный квартал», Корпус 1, кв. 12\nЭтаж 5 из 17 · Чистовая · сдача IV кв. 2027")
        ->and(json_decode($parts['reply_markup'], true))->toBe(['inline_keyboard' => [[
            ['text' => 'Записаться', 'callback_data' => "book:{$flat->id}"],
            ['text' => 'Похожие', 'callback_data' => "similar:{$flat->id}"],
        ]]]);
    Process::assertRan(fn ($process): bool => $process->command[0] === 'rsvg-convert' && $process->input === '<svg xmlns="http://www.w3.org/2000/svg"/>');
});

it('sends a plan sent before by Telegram\'s file ID without drawing it again', function () {
    fakeTelegramWithPlans();
    Process::fake(['*rsvg-convert*' => Process::result("PNG-BYTES\n")]);
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel);
    $flat->update(['plan_url' => 'https://developer.test/plans/2-54.svg']);

    deliver(agentReply($contact, flats: [$flat]));
    deliver(agentReply($contact, flats: [$flat]));

    $photos = telegramCalls('sendPhoto');
    expect($photos)->toHaveCount(2)
        ->and($photos[1]['photo'])->toBe('plan-file')
        ->and(Cache::get("telegram:{$contact->channel_id}:plan:".sha1('https://developer.test/plans/2-54.svg')))->toBe('plan-file');
    Process::assertRanTimes(fn ($process): bool => $process->command[0] === 'rsvg-convert', 1);
});

it('sends the card as text when the plan cannot be had', function () {
    fakeTelegramWithPlans(planStatus: 404);
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel);
    $flat->update(['plan_url' => 'https://developer.test/plans/2-54.svg']);

    deliver(agentReply($contact, flats: [$flat]));

    $card = telegramCalls('sendMessage')[1];
    expect($card['text'])->toStartWith('<b>2-комн. · 54,1 м² · 8 762 000 ₽</b>')
        ->and($card['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe("book:{$flat->id}")
        ->and(telegramCalls('sendPhoto'))->toBe([]);
});

it('leaves out the cards of flats sold since the turn', function () {
    fakeTelegramWithPlans();
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel, FlatStatus::Sold);

    deliver(agentReply($contact, flats: [$flat]));

    expect(telegramCalls('sendMessage'))->toHaveCount(1)
        ->and(telegramCalls('sendPhoto'))->toBe([]);
});

it('does not repeat the text when only the cards are left to deliver', function () {
    fakeTelegramWithPlans();
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel);
    $flat->update(['plan_url' => null]);
    $message = agentReply($contact, flats: [$flat]);
    $message->update(['delivered_at' => now()]);

    deliver($message);

    expect(telegramCalls('sendMessage'))->toHaveCount(1)
        ->and(telegramCalls('sendMessage')[0]['text'])->toStartWith('<b>2-комн.');
});

it('asks for the phone with a button once the dialog goes to a manager', function () {
    fakeTelegramWithPlans();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $message = agentReply($contact, 'Передаю вас менеджеру.');
    $message->conversation->handOff('запись на просмотр');

    deliver($message);

    expect(telegramCalls('sendMessage')[1])->toMatchArray([
        'text' => TelegramMessenger::PHONE_REQUEST,
        'reply_markup' => [
            'keyboard' => [[['text' => 'Отправить телефон', 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ],
    ]);
});

it('does not ask for a phone the buyer already shared', function () {
    fakeTelegramWithPlans();
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $message = agentReply($contact, 'Передаю вас менеджеру.');
    $message->conversation->handOff('запись на просмотр');

    deliver($message);

    expect(telegramCalls('sendMessage'))->toHaveCount(1);
});

it('sends the news of a subscription with the button that ends it', function () {
    fakeTelegramWithPlans();
    $contact = Contact::factory()->withPhone()->create(['channel_user_id' => '5001']);
    $conversation = Conversation::factory()->withContact($contact)->create();
    $search = SavedSearch::factory()->for($conversation)->create();
    $message = Message::factory()->for($conversation)->fromAgent('По вашей подписке появились квартиры.')->create(['saved_search_id' => $search->id]);

    deliver($message);

    expect(telegramCalls('sendMessage')[0]['reply_markup'])->toBe(['inline_keyboard' => [[
        ['text' => 'Отписаться', 'callback_data' => "unsubscribe:{$search->id}"],
    ]]]);
});
