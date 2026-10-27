<?php

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageRole;
use App\Jobs\AnswerConversation;
use App\Jobs\DeliverMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login form', function () {
    $this->get('/inbox')->assertRedirect('/login');
});

it('lists the developer\'s open conversations, the latest first, named by their buyers', function () {
    $tenant = Tenant::factory()->create();
    $manager = User::factory()->for($tenant)->create(['name' => 'Ольга']);
    $contact = Contact::factory()->for($tenant)->create(['name' => 'Анна']);
    $telegram = Conversation::factory()->withContact($contact)->takenBy($manager)->create(['last_message_at' => now()->subMinute()]);
    Message::factory()->for($telegram)->create(['text' => 'а скидку сделаете?']);
    $test = Conversation::factory()->for($tenant)->create(['last_message_at' => now()]);
    Message::factory()->for($test)->fromAgent(str_repeat('Подобрал две квартиры. ', 10))->create();
    Conversation::factory()->for($tenant)->create();
    Message::factory()->for(Conversation::factory()->for($tenant)->closed())->create();
    Message::factory()->for(Conversation::factory())->create();
    $this->actingAs($manager);

    $response = $this->get('/inbox');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('inbox/Index')
        ->where('filter', 'open')
        ->has('conversations', 2)
        ->where('conversations.0.title', "Тестовый диалог №{$test->id}")
        ->where('conversations.0.preview', fn (string $preview): bool => mb_strlen($preview) === 83 && str_ends_with($preview, '...'))
        ->where('conversations.0.last_role', 'agent')
        ->where('conversations.1', fn ($item): bool => $item['id'] === $telegram->id
            && $item['channel'] === 'telegram'
            && $item['title'] === 'Анна'
            && $item['preview'] === 'а скидку сделаете?'
            && $item['assignee'] === 'Ольга'));
});

it('lists the conversations waiting for a manager, the employee\'s own and the closed ones', function (string $filter, string $expected) {
    $tenant = Tenant::factory()->create();
    $manager = User::factory()->for($tenant)->create();
    $conversations = [
        'ai' => Conversation::factory()->for($tenant)->create(),
        'waiting' => Conversation::factory()->for($tenant)->handedOff()->create(),
        'mine' => Conversation::factory()->takenBy($manager)->create(),
        'another' => Conversation::factory()->takenBy(User::factory()->for($tenant)->create())->create(),
        'closed' => Conversation::factory()->for($tenant)->closed()->create(),
    ];
    foreach ($conversations as $conversation) {
        Message::factory()->for($conversation)->create();
    }
    $this->actingAs($manager);

    $response = $this->get("/inbox?filter={$filter}");

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filter', $filter)
        ->where('conversations', fn ($items): bool => collect($items)->pluck('id')->all() === [$conversations[$expected]->id])
        ->where('counts', ['waiting' => 1, 'mine' => 1]));
})->with([
    'waiting' => ['waiting', 'waiting'],
    'mine' => ['mine', 'mine'],
    'closed' => ['closed', 'closed'],
]);

it('shows the conversation with its buyer, the managers\' names and what the employee may do', function () {
    $tenant = Tenant::factory()->create();
    $manager = User::factory()->for($tenant)->create(['name' => 'Ольга']);
    $contact = Contact::factory()->for($tenant)->withPhone()->create(['name' => 'Анна', 'username' => 'anna_p']);
    $conversation = Conversation::factory()->withContact($contact)->takenBy($manager)->create(['handoff_reason' => 'торг']);
    Message::factory()->for($conversation)->create(['text' => 'а скидку сделаете?']);
    Message::factory()->for($conversation)->fromManager($manager, 'Уточню у руководителя.')->create(['delivered_at' => now()]);
    $this->actingAs($manager);

    $response = $this->get("/inbox/{$conversation->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->where('conversation.title', 'Анна')
        ->where('conversation.handoff_reason', 'торг')
        ->where('conversation.assignee', ['id' => $manager->id, 'name' => 'Ольга'])
        ->where('conversation.contact', ['name' => 'Анна', 'username' => 'anna_p', 'phone' => '+79161234567'])
        ->where('conversation.lead', null)
        ->where('conversation.can', ['take' => false, 'reply' => true, 'hand_back' => true, 'close' => true])
        ->has('messages', 2)
        ->where('messages.0.author', null)
        ->where('messages.1.author', 'Ольга')
        ->where('messages.1.delivered_at', fn (?string $at): bool => $at !== null)
        ->where('waiting', false));
});

it('shows the lead card of the conversation and the scores in the list', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    Message::factory()->for($conversation)->create();
    Lead::factory()->for($conversation)->hot()->create(['family' => 'двое детей']);
    $this->actingAs(User::factory()->for($tenant)->create());

    $response = $this->get("/inbox/{$conversation->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->where('conversations.0.score', 'hot')
        ->where('conversation.lead', [
            'score' => 'hot', 'budget' => 9_000_000, 'payment' => 'mortgage', 'rooms' => [2], 'deadline_year' => 2027,
            'family' => 'двое детей', 'sells_own_flat' => null, 'wants_viewing' => true,
        ]));
});

it('shows what the buyer waits for: the active subscriptions from all their dialogs', function () {
    $tenant = Tenant::factory()->create();
    $contact = Contact::factory()->for($tenant)->create();
    $conversation = Conversation::factory()->withContact($contact)->create();
    Message::factory()->for($conversation)->create();
    SavedSearch::factory()->for(Conversation::factory()->withContact($contact)->closed())->create(['description' => '2-комн., до 9 млн ₽']);
    SavedSearch::factory()->for($conversation)->inactive()->create(['description' => 'студия']);
    SavedSearch::factory()->for(Conversation::factory()->withContact(Contact::factory()->for($tenant)->create()))->create(['description' => 'трёшка']);
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->get("/inbox/{$conversation->id}")->assertInertia(fn (Assert $page) => $page
        ->where('conversation.saved_searches', ['2-комн., до 9 млн ₽']));
});

it('shows that the agent is answering the buyer\'s latest message', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    Message::factory()->for($conversation)->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->get("/inbox/{$conversation->id}")->assertInertia(fn (Assert $page) => $page
        ->where('waiting', true)
        ->where('conversation.can', ['take' => true, 'reply' => false, 'hand_back' => false, 'close' => true]));
});

it('hides another developer\'s conversation', function () {
    Queue::fake([AnswerConversation::class, DeliverMessage::class]);
    $conversation = Conversation::factory()->handedOff()->create();
    $this->actingAs(User::factory()->create());

    $this->get("/inbox/{$conversation->id}")->assertNotFound();
    $this->post("/inbox/{$conversation->id}/take")->assertNotFound();
    $this->post("/inbox/{$conversation->id}/messages", ['text' => 'Здравствуйте'])->assertNotFound();
    $this->post("/inbox/{$conversation->id}/hand-back")->assertNotFound();
    $this->post("/inbox/{$conversation->id}/close")->assertNotFound();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Human)
        ->and($conversation->assignee_id)->toBeNull();
});

it('takes the conversation from the agent for the manager', function () {
    $tenant = Tenant::factory()->create();
    $manager = User::factory()->for($tenant)->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    $this->actingAs($manager);

    $this->from("/inbox/{$conversation->id}")->post("/inbox/{$conversation->id}/take")->assertRedirect("/inbox/{$conversation->id}");

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Human)
        ->and($conversation->assignee_id)->toBe($manager->id)
        ->and($conversation->handoff_reason)->toBeNull();
});

it('keeps why the agent handed the conversation over when a manager takes it', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->handedOff('торг')->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/inbox/{$conversation->id}/take")->assertRedirect();

    expect($conversation->refresh()->handoff_reason)->toBe('торг');
});

it('does not let a manager take another manager\'s conversation', function () {
    $tenant = Tenant::factory()->create();
    $owner = User::factory()->for($tenant)->create();
    $conversation = Conversation::factory()->takenBy($owner)->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/inbox/{$conversation->id}/take")->assertForbidden();

    expect($conversation->refresh()->assignee_id)->toBe($owner->id);
});

it('sends the manager\'s message to the buyer\'s channel', function () {
    Queue::fake([DeliverMessage::class]);
    $tenant = Tenant::factory()->create();
    $manager = User::factory()->for($tenant)->create();
    $conversation = Conversation::factory()->withContact(Contact::factory()->for($tenant)->create())->takenBy($manager)->create();
    $this->actingAs($manager);

    $this->post("/inbox/{$conversation->id}/messages", ['text' => '  Скидку дать не могу, но есть акция. '])->assertRedirect();

    $message = $conversation->messages()->sole();
    expect($message->role)->toBe(MessageRole::Manager)
        ->and($message->user_id)->toBe($manager->id)
        ->and($message->text)->toBe('Скидку дать не могу, но есть акция.')
        ->and($conversation->refresh()->last_message_at->equalTo($message->created_at))->toBeTrue();
    Queue::assertPushed(DeliverMessage::class, fn (DeliverMessage $job): bool => $job->message->is($message));
});

it('shows the manager\'s message in the test chat without delivering it anywhere', function () {
    Queue::fake([DeliverMessage::class]);
    $manager = User::factory()->create();
    $conversation = Conversation::factory()->takenBy($manager)->create(['channel' => ConversationChannel::Test]);
    $this->actingAs($manager);

    $this->post("/inbox/{$conversation->id}/messages", ['text' => 'Здравствуйте!'])->assertRedirect();

    expect($conversation->messages()->sole()->role)->toBe(MessageRole::Manager);
    Queue::assertNotPushed(DeliverMessage::class);
});

it('does not let a manager write in a conversation they have not taken', function () {
    Queue::fake([DeliverMessage::class]);
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->handedOff()->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/inbox/{$conversation->id}/messages", ['text' => 'Здравствуйте!'])->assertForbidden();

    expect($conversation->messages()->exists())->toBeFalse();
    Queue::assertNotPushed(DeliverMessage::class);
});

it('rejects an empty message of the manager', function () {
    $manager = User::factory()->create();
    $conversation = Conversation::factory()->takenBy($manager)->create();
    $this->actingAs($manager);

    $this->post("/inbox/{$conversation->id}/messages", ['text' => ' '])->assertSessionHasErrors(['text' => 'The text field is required.']);
});

it('hands the conversation back to the agent, which answers the buyer still waiting', function () {
    Queue::fake([AnswerConversation::class]);
    $manager = User::factory()->create();
    $conversation = Conversation::factory()->takenBy($manager)->create(['handoff_reason' => 'торг']);
    Message::factory()->for($conversation)->create(['text' => 'а что по ипотеке?']);
    $this->actingAs($manager);

    $this->post("/inbox/{$conversation->id}/hand-back")->assertRedirect();

    $conversation->refresh();
    expect($conversation->status)->toBe(ConversationStatus::Ai)
        ->and($conversation->assignee_id)->toBeNull()
        ->and($conversation->handoff_reason)->toBeNull();
    Queue::assertPushedOn('agent', AnswerConversation::class, fn (AnswerConversation $job): bool => $job->conversation->is($conversation));
});

it('does not have the agent answer after the manager\'s own last word', function () {
    Queue::fake([AnswerConversation::class]);
    $manager = User::factory()->create();
    $conversation = Conversation::factory()->takenBy($manager)->create();
    Message::factory()->for($conversation)->create();
    Message::factory()->for($conversation)->fromManager($manager)->create();
    $this->actingAs($manager);

    $this->post("/inbox/{$conversation->id}/hand-back")->assertRedirect();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Ai);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('does not let a manager hand back another manager\'s conversation', function () {
    Queue::fake([AnswerConversation::class]);
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->takenBy(User::factory()->for($tenant)->create())->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/inbox/{$conversation->id}/hand-back")->assertForbidden();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Human);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('closes the conversation', function () {
    $manager = User::factory()->create();
    $conversation = Conversation::factory()->takenBy($manager)->create();
    $this->actingAs($manager);

    $this->post("/inbox/{$conversation->id}/close")->assertRedirect();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Closed);
});

it('does not let a manager close another manager\'s conversation', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->takenBy(User::factory()->for($tenant)->create())->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/inbox/{$conversation->id}/close")->assertForbidden();

    expect($conversation->refresh()->status)->toBe(ConversationStatus::Human);
});

it('names a conversation of the site widget by its visitor, who has no name', function () {
    $tenant = Tenant::factory()->create();
    $visitor = Contact::factory()->for(Channel::factory()->for($tenant)->widget())->create(['name' => null]);
    $conversation = Conversation::factory()->withContact($visitor)->create(['last_message_at' => now()]);
    Message::factory()->for($conversation)->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->get('/inbox')->assertInertia(fn (Assert $page) => $page
        ->where('conversations.0.channel', 'widget')
        ->where('conversations.0.title', "Посетитель сайта №{$conversation->id}"));
});
