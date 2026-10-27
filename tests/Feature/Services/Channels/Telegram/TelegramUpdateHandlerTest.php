<?php

use App\Enums\ConversationStatus;
use App\Enums\FlatStatus;
use App\Enums\LeadPayment;
use App\Enums\LeadScore;
use App\Enums\MessageRole;
use App\Jobs\AnswerConversation;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Services\Channels\Telegram\TelegramUpdateHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An update with a press of a button under the bot's message.
 *
 * @return array<string, mixed>
 */
function telegramPress(string $data, int $chatId = 5001, int $updateId = 2): array
{
    return ['update_id' => $updateId, 'callback_query' => [
        'id' => 'callback-1',
        'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Анна'],
        'message' => ['message_id' => 20, 'chat' => ['id' => $chatId, 'type' => 'private']],
        'data' => $data,
    ]];
}

function handleTelegram(Channel $channel, array $update): void
{
    app(TelegramUpdateHandler::class)->handle($channel, $update);
}

it('asks a new buyer for consent with the policy link and keeps the question for after it', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $channel = Channel::factory()->create(['settings' => ['policy_url' => 'https://developer.test/privacy']]);

    handleTelegram($channel, telegramMessage('двушка до 9 млн'));

    $contact = Contact::withoutGlobalScopes()->sole();
    expect($contact->tenant_id)->toBe($channel->tenant_id)
        ->and($contact->channel_id)->toBe($channel->id)
        ->and($contact->channel_user_id)->toBe('5001')
        ->and($contact->name)->toBe('Анна Петрова')
        ->and($contact->username)->toBe('anna_p')
        ->and($contact->consent_at)->toBeNull();
    $message = Message::withoutGlobalScopes()->sole();
    expect($message->role)->toBe(MessageRole::Buyer)
        ->and($message->text)->toBe('двушка до 9 млн')
        ->and($message->conversation->contact_id)->toBe($contact->id);
    $request = telegramCalls('sendMessage')[0];
    expect($request['chat_id'])->toBe('5001')
        ->and($request['text'])->toContain('согласие на обработку персональных данных: https://developer.test/privacy')
        ->and($request['reply_markup'])->toBe(['inline_keyboard' => [[['text' => 'Согласен', 'callback_data' => 'consent']]]]);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('greets a new buyer on /start with the consent request and keeps no message', function () {
    fakeTelegram();
    $channel = Channel::factory()->create();

    handleTelegram($channel, telegramMessage('/start'));

    expect(Message::withoutGlobalScopes()->count())->toBe(0)
        ->and(telegramCalls('sendMessage')[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('consent');
});

it('answers the question a buyer asked before agreeing once the buyer agrees', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $this->freezeSecond();
    $contact = Contact::factory()->withoutConsent()->create(['channel_user_id' => '5001']);
    $conversation = Conversation::factory()->withContact($contact)->create();
    Message::factory()->for($conversation)->create();

    handleTelegram($contact->channel, telegramPress('consent'));

    expect($contact->fresh()->consent_at->equalTo(now()))->toBeTrue()
        ->and(telegramCalls('answerCallbackQuery'))->toHaveCount(1)
        ->and(telegramCalls('editMessageReplyMarkup'))->toBe([['chat_id' => '5001', 'message_id' => 20]]);
    Queue::assertPushed(AnswerConversation::class, fn (AnswerConversation $job): bool => $job->conversation->is($conversation));
});

it('thanks a buyer who agrees with no question waiting', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->withoutConsent()->create(['channel_user_id' => '5001']);

    handleTelegram($contact->channel, telegramPress('consent'));

    expect(telegramCalls('sendMessage')[0]['text'])->toBe(TelegramUpdateHandler::THANKS_FOR_CONSENT);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('queues the agent\'s answer to a buyer who agreed and shows that the bot is typing', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $conversation = Conversation::factory()->withContact($contact)->create();

    handleTelegram($contact->channel, telegramMessage('а с отделкой есть?'));

    expect($conversation->messages()->sole()->text)->toBe('а с отделкой есть?')
        ->and(telegramCalls('sendChatAction'))->toBe([['chat_id' => '5001', 'action' => 'typing']])
        ->and(telegramCalls('sendMessage'))->toBe([]);
    Queue::assertPushedOn('agent', AnswerConversation::class, fn (AnswerConversation $job): bool => $job->conversation->is($conversation));
});

it('keeps the buyer\'s message for the manager without the agent once the conversation is handed over', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $conversation = Conversation::factory()->withContact($contact)->handedOff()->create();

    handleTelegram($contact->channel, telegramMessage('когда перезвоните?'));

    expect($conversation->messages()->sole()->text)->toBe('когда перезвоните?');
    Queue::assertNotPushed(AnswerConversation::class);
});

it('starts a new conversation once the buyer\'s last one is closed', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $closed = Conversation::factory()->withContact($contact)->create(['status' => ConversationStatus::Closed]);

    handleTelegram($contact->channel, telegramMessage());

    $conversation = $contact->conversations()->latest('id')->first();
    expect($conversation->is($closed))->toBeFalse()
        ->and($conversation->status)->toBe(ConversationStatus::Ai)
        ->and($conversation->messages()->count())->toBe(1);
});

it('asks for text when the buyer sends something else', function () {
    fakeTelegram();
    $channel = Channel::factory()->create();
    $voice = telegramMessage(message: ['text' => null, 'voice' => ['file_id' => 'voice-1', 'duration' => 3]]);

    handleTelegram($channel, $voice);

    expect(telegramCalls('sendMessage')[0]['text'])->toBe(TelegramUpdateHandler::TEXT_ONLY)
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('stays out of group chats', function () {
    fakeTelegram();
    $channel = Channel::factory()->create();

    handleTelegram($channel, telegramMessage(message: ['chat' => ['id' => -100500, 'type' => 'supergroup']]));

    Http::assertNothingSent();
    expect(Contact::withoutGlobalScopes()->count())->toBe(0);
});

it('handles an update Telegram delivers twice only once', function () {
    fakeTelegram();
    $channel = Channel::factory()->create();

    handleTelegram($channel, telegramMessage(updateId: 7));
    handleTelegram($channel, telegramMessage(updateId: 7));

    expect(Message::withoutGlobalScopes()->count())->toBe(1);
});

it('ignores the updates of a channel the developer switched off', function () {
    fakeTelegram();
    $channel = Channel::factory()->inactive()->create();

    handleTelegram($channel, telegramMessage());

    Http::assertNothingSent();
    expect(Contact::withoutGlobalScopes()->count())->toBe(0);
});

it('saves the phone the buyer shared with the button and hides the button', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);

    handleTelegram($contact->channel, telegramMessage(message: [
        'text' => null,
        'contact' => ['phone_number' => '79161234567', 'first_name' => 'Анна', 'user_id' => 5001],
    ]));

    expect($contact->fresh()->phone)->toBe('+79161234567')
        ->and(telegramCalls('sendMessage')[0])->toMatchArray([
            'text' => TelegramUpdateHandler::THANKS_FOR_PHONE,
            'reply_markup' => ['remove_keyboard' => true],
        ]);
});

it('warms up the buyer\'s lead once they can be called', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $lead = Lead::factory()->for(Conversation::factory()->withContact($contact))->create([
        'contact_id' => $contact->id, 'payment' => LeadPayment::Mortgage, 'deadline_year' => 2027,
    ]);
    expect($lead->score)->toBe(LeadScore::Warm);

    handleTelegram($contact->channel, telegramMessage(message: [
        'text' => null,
        'contact' => ['phone_number' => '79161234567', 'first_name' => 'Анна', 'user_id' => 5001],
    ]));

    expect($lead->fresh()->score)->toBe(LeadScore::Hot);
});

it('does not take a forwarded contact of someone else for the buyer\'s phone', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);

    handleTelegram($contact->channel, telegramMessage(message: [
        'text' => null,
        'contact' => ['phone_number' => '79990000000', 'first_name' => 'Сосед', 'user_id' => 6002],
    ]));

    expect($contact->fresh()->phone)->toBeNull();
});

it('turns the buttons of a flat card into the buyer\'s request', function (string $button, string $request) {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel);

    handleTelegram($contact->channel, telegramPress($button.$flat->id));

    $message = $contact->conversations()->sole()->messages()->sole();
    expect($message->role)->toBe(MessageRole::Buyer)
        ->and($message->text)->toBe($request)
        ->and(telegramCalls('answerCallbackQuery'))->toBe([['callback_query_id' => 'callback-1']]);
    Queue::assertPushed(AnswerConversation::class);
})->with([
    'book' => ['book:', 'Хочу записаться на просмотр: кв. 12 в ЖК «Отрадный квартал»'],
    'similar' => ['similar:', 'Покажите похожие на кв. 12 в ЖК «Отрадный квартал»: двушка до 9,7 млн ₽'],
]);

it('tells the buyer a flat from an old card is sold', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel, FlatStatus::Sold);

    handleTelegram($contact->channel, telegramPress("book:{$flat->id}"));

    expect(telegramCalls('answerCallbackQuery'))->toBe([['callback_query_id' => 'callback-1', 'text' => TelegramUpdateHandler::FLAT_GONE]])
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let a button name another developer\'s flat', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $otherFlat = flatOfChannel(Channel::factory()->create());

    handleTelegram($contact->channel, telegramPress("similar:{$otherFlat->id}"));

    expect(telegramCalls('answerCallbackQuery')[0]['text'])->toBe(TelegramUpdateHandler::FLAT_GONE)
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('asks for consent before a card\'s button is taken', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->withoutConsent()->create(['channel_user_id' => '5001']);
    $flat = flatOfChannel($contact->channel);

    handleTelegram($contact->channel, telegramPress("book:{$flat->id}"));

    expect(telegramCalls('sendMessage')[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('consent')
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('only stops the spinner of a button pressed in a chat the bot does not know', function () {
    fakeTelegram();
    $channel = Channel::factory()->create();

    handleTelegram($channel, telegramPress('consent', chatId: 7777));

    expect(telegramCalls('answerCallbackQuery'))->toHaveCount(1)
        ->and(telegramCalls('sendMessage'))->toBe([])
        ->and(Contact::withoutGlobalScopes()->count())->toBe(0);
});

it('stops answering a buyer past the hourly limit and says so once', function () {
    fakeTelegram();
    Queue::fake([AnswerConversation::class]);
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);

    foreach (range(1, TelegramUpdateHandler::MESSAGES_PER_HOUR) as $sent) {
        RateLimiter::hit("telegram-contact:{$contact->id}", 3600);
    }

    handleTelegram($contact->channel, telegramMessage(updateId: 41));
    handleTelegram($contact->channel, telegramMessage(updateId: 42));

    expect(telegramCalls('sendMessage'))->toBe([['chat_id' => '5001', 'text' => TelegramUpdateHandler::TOO_MANY_MESSAGES, 'link_preview_options' => ['is_disabled' => true]]])
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('ends the buyer\'s subscription with the button under its news and hides the button', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $search = SavedSearch::factory()->for(Conversation::factory()->withContact($contact))->create();

    handleTelegram($contact->channel, telegramPress("unsubscribe:{$search->id}"));

    expect($search->fresh()->active)->toBeFalse()
        ->and(telegramCalls('answerCallbackQuery'))->toBe([['callback_query_id' => 'callback-1', 'text' => TelegramUpdateHandler::UNSUBSCRIBED]])
        ->and(telegramCalls('editMessageReplyMarkup'))->toBe([['chat_id' => '5001', 'message_id' => 20]])
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let a button end another buyer\'s subscription', function () {
    fakeTelegram();
    $contact = Contact::factory()->create(['channel_user_id' => '5001']);
    $other = SavedSearch::factory()->for(Conversation::factory()->withContact(Contact::factory()->for($contact->channel)->create()))->create();

    handleTelegram($contact->channel, telegramPress("unsubscribe:{$other->id}"));

    expect($other->fresh()->active)->toBeTrue()
        ->and(telegramCalls('answerCallbackQuery'))->toBe([['callback_query_id' => 'callback-1']]);
});
