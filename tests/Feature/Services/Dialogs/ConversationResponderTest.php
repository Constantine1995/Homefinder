<?php

use App\Enums\ConversationStatus;
use App\Enums\LeadPayment;
use App\Enums\LeadScore;
use App\Enums\MessageRole;
use App\Models\AgentRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Dialogs\ConversationResponder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * What the agent service answers to one message of the buyer.
 *
 * @param  list<array{type: string, reason: string}>  $actions
 * @return array<string, mixed>
 */
function agentTurn(string $reply = 'Нашёл кв. 75 за 8 762 000 ₽.', array $actions = []): array
{
    return [
        'reply' => $reply,
        'dialog' => ['filters' => ['rooms' => [2]], 'shown_flat_ids' => [75], 'lead' => ['budget' => 9_000_000]],
        'intent' => 'search',
        'actions' => $actions,
        'steps' => [['tool' => 'search_flats', 'args' => ['request' => 'двушка'], 'summary' => 'Найдено квартир: 1.', 'data' => ['total' => 1], 'flat_ids' => [75]]],
        'verified' => true,
        'problems' => [],
        'attempts' => 1,
        'prompt_tokens' => 1800,
        'completion_tokens' => 120,
    ];
}

it('answers the buyer\'s latest message for the conversation\'s developer, with the history and the kept dialog state', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create(['dialog' => ['wishes' => 'тихий двор']]);
    Message::factory()->for($conversation)->create(['text' => 'здравствуйте']);
    Message::factory()->for($conversation)->fromAgent('Здравствуйте! Что ищете?')->create();
    Message::factory()->for($conversation)->create(['text' => 'двушка до 9 млн']);
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn())]);

    app(ConversationResponder::class)->respond($conversation);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'tenant_id' => $conversation->tenant_id,
        'history' => [
            ['role' => 'user', 'text' => 'здравствуйте'],
            ['role' => 'assistant', 'text' => 'Здравствуйте! Что ищете?'],
        ],
        'message' => 'двушка до 9 млн',
        'cards' => false,
        'dialog' => ['wishes' => 'тихий двор'],
    ]);
    $reply = Message::withoutGlobalScopes()->latest('id')->first();
    expect($reply->role)->toBe(MessageRole::Agent)
        ->and($reply->text)->toBe('Нашёл кв. 75 за 8 762 000 ₽.')
        ->and($reply->tool_calls)->toEqual([['tool' => 'search_flats', 'args' => ['request' => 'двушка']]])
        ->and($reply->tenant_id)->toBe($conversation->tenant_id);
    $run = AgentRun::withoutGlobalScopes()->sole();
    expect($run->message_id)->toBe($reply->id)
        ->and($run->tenant_id)->toBe($conversation->tenant_id)
        ->and($run->intent)->toBe('search')
        ->and($run->verified)->toBeTrue()
        ->and($run->steps[0]['summary'])->toBe('Найдено квартир: 1.')
        ->and($run->filters)->toBe(['rooms' => [2]])
        ->and([$run->prompt_tokens, $run->completion_tokens])->toBe([1800, 120]);
    $conversation->refresh();
    expect($conversation->dialog)->toEqual(agentTurn()['dialog'])
        ->and($conversation->status)->toBe(ConversationStatus::Ai)
        ->and($conversation->last_message_at->equalTo($reply->created_at))->toBeTrue();
});

it('logs search conditions only for a turn that searched flats', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['text' => 'где офис продаж?']);
    Http::fake(['agent.invalid/v1/chat' => Http::response([...agentTurn('Офис на Ленина, 1.'), 'intent' => 'knowledge', 'steps' => []])]);

    app(ConversationResponder::class)->respond($conversation);

    expect(AgentRun::withoutGlobalScopes()->sole()->filters)->toBeNull();
});

it('subscribes the buyer who asked the agent, once for the same conditions', function () {
    Http::preventStrayRequests();
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->withContact($contact)->create();
    $filters = ['rooms' => [2], 'price_to' => 9_000_000, 'statuses' => ['available']];
    $ended = SavedSearch::factory()->for(Conversation::factory()->withContact($contact)->closed())->inactive()->create(['filters' => $filters]);
    Message::factory()->for($conversation)->create(['text' => 'напишите, если появится двушка до 9 млн']);
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn(
        'Готово: напишу, когда появится.',
        [['type' => 'save_search', 'reason' => '2-комн., до 9 млн ₽', 'filters' => $filters]],
    ))]);

    app(ConversationResponder::class)->respond($conversation);
    app(ConversationResponder::class)->respond(tap($conversation, fn () => Message::factory()->for($conversation)->create(['text' => 'и ещё раз: напишите'])));

    $search = SavedSearch::withoutGlobalScopes()->sole();
    expect($search->id)->toBe($ended->id)
        ->and($search->active)->toBeTrue()
        ->and($search->description)->toBe('2-комн., до 9 млн ₽')
        ->and($conversation->fresh()->status)->toBe(ConversationStatus::Ai);
});

it('subscribes a test dialog by the dialog itself', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['text' => 'напишите, если появится трёшка']);
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn(
        'Готово.',
        [['type' => 'save_search', 'reason' => '3-комн.', 'filters' => ['rooms' => [3]]]],
    ))]);

    app(ConversationResponder::class)->respond($conversation);

    expect(SavedSearch::withoutGlobalScopes()->sole())
        ->conversation_id->toBe($conversation->id)
        ->contact_id->toBeNull()
        ->filters->toBe(['rooms' => [3]]);
});

it('tells the agent that a Telegram buyer sees the flats found as cards', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->withContact()->create();
    Message::factory()->for($conversation)->create();
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn())]);

    app(ConversationResponder::class)->respond($conversation);

    Http::assertSent(fn (Request $request): bool => $request['cards'] === true);
});

it('hands the conversation over when the agent asks for a manager', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['text' => 'а скидку дадите?']);
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn(
        'Скидку обсудит менеджер — он скоро напишет.',
        [['type' => 'handoff', 'reason' => 'просит скидку']],
    ))]);

    app(ConversationResponder::class)->respond($conversation);

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Human)
        ->and($conversation->handoff_reason)->toBe('просит скидку')
        ->and($conversation->messages()->latest('id')->first()->text)->toBe('Скидку обсудит менеджер — он скоро напишет.');
});

it('keeps the agent answering when it only notes a question the knowledge base has no answer to', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['text' => 'есть бассейн?']);
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn(
        'Уточню у менеджера и вернусь с ответом.',
        [['type' => 'knowledge_gap', 'reason' => 'есть бассейн?']],
    ))]);

    app(ConversationResponder::class)->respond($conversation);

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Ai)
        ->and(AgentRun::withoutGlobalScopes()->sole()->actions)->toEqual([['type' => 'knowledge_gap', 'reason' => 'есть бассейн?']]);
});

it('hands the conversation over with a notice to the buyer when the agent service is down', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();
    Http::fake(['agent.invalid/v1/chat' => Http::response(['detail' => 'The language model is unavailable'], 503)]);

    app(ConversationResponder::class)->respond($conversation);

    $conversation->refresh();
    $notice = $conversation->messages()->latest('id')->first();
    expect($conversation->status)->toBe(ConversationStatus::Human)
        ->and($conversation->handoff_reason)->toBe('сервис ИИ недоступен')
        ->and($notice->role)->toBe(MessageRole::Agent)
        ->and($notice->text)->toBe('Передаю ваш вопрос менеджеру отдела продаж — он ответит вам в ближайшее время.');
    $run = AgentRun::withoutGlobalScopes()->sole();
    expect($run->message_id)->toBe($notice->id)
        ->and($run->verified)->toBeFalse()
        ->and($run->error)->toContain('HTTP 503');
});

it('drops the reply when a manager takes the conversation during the turn, keeping the turn\'s log', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create(['dialog' => ['wishes' => 'тихий двор']]);
    $manager = User::factory()->for($conversation->tenant)->create();
    Message::factory()->for($conversation)->create();
    Http::fake(['agent.invalid/v1/chat' => function () use ($conversation, $manager) {
        Conversation::query()->findOrFail($conversation->id)->takeBy($manager);

        return Http::response(agentTurn(actions: [['type' => 'handoff', 'reason' => 'торг']]));
    }]);

    $reply = app(ConversationResponder::class)->respond($conversation);

    $conversation->refresh();
    expect($reply)->toBeNull()
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->assignee_id)->toBe($manager->id)
        ->and($conversation->handoff_reason)->toBeNull()
        ->and($conversation->dialog)->toBe(['wishes' => 'тихий двор']);
    $run = AgentRun::withoutGlobalScopes()->sole();
    expect($run->message_id)->toBeNull()
        ->and($run->error)->toBe(ConversationResponder::TAKEN_DURING_TURN)
        ->and($run->prompt_tokens)->toBe(1800);
});

it('sends no notice when a manager takes the conversation while the agent service fails', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    $manager = User::factory()->for($conversation->tenant)->create();
    Message::factory()->for($conversation)->create();
    Http::fake(['agent.invalid/v1/chat' => function () use ($conversation, $manager) {
        Conversation::query()->findOrFail($conversation->id)->takeBy($manager);

        return Http::response(['detail' => 'The language model is unavailable'], 503);
    }]);

    app(ConversationResponder::class)->respond($conversation);

    $conversation->refresh();
    expect($conversation->messages()->count())->toBe(1)
        ->and($conversation->handoff_reason)->toBeNull();
});

it('keeps the lead card the agent has heard so far, for the conversation\'s buyer', function () {
    Http::preventStrayRequests();
    $contact = Contact::factory()->create();
    $conversation = Conversation::factory()->withContact($contact)->create();
    Message::factory()->for($conversation)->create(['text' => 'двушка или трёшка до 9 млн в ипотеку, сдача до 2027']);
    $turn = agentTurn();
    $turn['dialog']['lead'] = [
        'budget' => 9_000_000, 'payment' => 'mortgage', 'rooms' => [2, 3], 'deadline_year' => 2027,
        'family' => 'двое детей', 'sells_own_flat' => null,
    ];
    Http::fake(['agent.invalid/v1/chat' => Http::response($turn)]);

    app(ConversationResponder::class)->respond($conversation);

    $lead = Lead::withoutGlobalScopes()->sole();
    expect($lead->conversation_id)->toBe($conversation->id)
        ->and($lead->contact_id)->toBe($contact->id)
        ->and($lead->tenant_id)->toBe($conversation->tenant_id)
        ->and([$lead->budget, $lead->payment, $lead->rooms, $lead->deadline_year, $lead->family, $lead->sells_own_flat])
        ->toBe([9_000_000, LeadPayment::Mortgage, [2, 3], 2027, 'двое детей', null])
        ->and($lead->wants_viewing)->toBeFalse()
        ->and($lead->score)->toBe(LeadScore::Warm);
});

it('makes no lead of a dialog where the buyer has told nothing about the purchase', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create(['text' => 'добрый вечер']);
    $turn = agentTurn('Добрый вечер! Чем помочь?');
    $turn['intent'] = 'smalltalk';
    $turn['dialog']['lead'] = ['budget' => null, 'payment' => null, 'rooms' => [], 'deadline_year' => null, 'family' => null, 'sells_own_flat' => null];
    Http::fake(['agent.invalid/v1/chat' => Http::response($turn)]);

    app(ConversationResponder::class)->respond($conversation);

    expect(Lead::withoutGlobalScopes()->exists())->toBeFalse();
});

it('remembers that the buyer asked for a viewing, and makes the lead hot', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Lead::factory()->for($conversation)->create(['payment' => LeadPayment::Mortgage]);
    Message::factory()->for($conversation)->create(['text' => 'хочу записаться на просмотр']);
    $booking = agentTurn('Передаю менеджеру, он подберёт время.', [['type' => 'handoff', 'reason' => 'запись на просмотр']]);
    $booking['intent'] = 'booking';
    $booking['dialog']['lead'] = ['budget' => 9_000_000, 'payment' => 'mortgage', 'rooms' => [2]];
    Http::fake(['agent.invalid/v1/chat' => Http::response($booking)]);

    app(ConversationResponder::class)->respond($conversation);

    $lead = $conversation->lead()->sole();
    expect($lead->wants_viewing)->toBeTrue()
        ->and($lead->score)->toBe(LeadScore::Hot);
});

it('keeps the request for a viewing through the turns after it', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Lead::factory()->for($conversation)->create(['wants_viewing' => true]);
    Message::factory()->for($conversation)->create(['text' => 'а паркинг есть?']);
    Http::fake(['agent.invalid/v1/chat' => Http::response(agentTurn())]);

    app(ConversationResponder::class)->respond($conversation);

    expect($conversation->lead()->sole()->wants_viewing)->toBeTrue();
});

it('leaves a conversation a manager answers to the manager', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->handedOff()->create();
    Message::factory()->for($conversation)->create();

    app(ConversationResponder::class)->respond($conversation);

    Http::assertNothingSent();
    expect(Message::withoutGlobalScopes()->count())->toBe(1);
});

it('does not answer twice when the buyer\'s message already has a reply', function () {
    Http::preventStrayRequests();
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();
    Message::factory()->for($conversation)->fromAgent()->create();

    app(ConversationResponder::class)->respond($conversation);

    Http::assertNothingSent();
});
