<?php

use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Jobs\AnswerConversation;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login form', function () {
    $this->get('/chat')->assertRedirect('/login');
});

it('starts a test conversation of the employee\'s developer', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post('/chat');

    $conversation = Conversation::sole();
    $response->assertRedirect("/chat/{$conversation->id}");
    expect($conversation->tenant_id)->toBe($user->tenant_id)
        ->and($conversation->channel)->toBe(ConversationChannel::Test);
});

it('stores the buyer\'s message and queues the agent\'s answer', function () {
    Queue::fake([AnswerConversation::class]);
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $response = $this->post("/chat/{$conversation->id}/messages", ['text' => '  двушка до 9 млн ']);

    $response->assertRedirect("/chat/{$conversation->id}");
    $message = $conversation->messages()->sole();
    expect($message->role)->toBe(MessageRole::Buyer)
        ->and($message->text)->toBe('двушка до 9 млн');
    Queue::assertPushedOn('agent', AnswerConversation::class, fn (AnswerConversation $job): bool => $job->conversation->is($conversation));
});

it('does not queue the agent for a conversation a manager answers', function () {
    Queue::fake([AnswerConversation::class]);
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->handedOff()->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/chat/{$conversation->id}/messages", ['text' => 'алло?'])->assertRedirect();

    expect($conversation->messages()->count())->toBe(1);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('rejects an empty message', function () {
    Queue::fake([AnswerConversation::class]);
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $response = $this->post("/chat/{$conversation->id}/messages", ['text' => '   ']);

    $response->assertSessionHasErrors(['text' => 'The text field is required.']);
    Queue::assertNotPushed(AnswerConversation::class);
});

it('hides another developer\'s conversation', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();
    $this->actingAs(User::factory()->create());

    $this->get("/chat/{$conversation->id}")->assertNotFound();
    $this->post("/chat/{$conversation->id}/messages", ['text' => 'двушка'])->assertNotFound();
});

it('shows the dialog with the agent\'s turns and waits while the buyer\'s message has no reply', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    Message::factory()->for($conversation)->create(['text' => 'двушка до 9 млн']);
    $reply = Message::factory()->for($conversation)->fromAgent('Нашёл кв. 75.')->create();
    AgentRun::factory()->for($conversation)->for($reply)->create([
        'steps' => [['tool' => 'search_flats', 'args' => ['request' => 'двушка'], 'summary' => 'Найдено квартир: 1.', 'data' => ['flats' => []]]],
    ]);
    Message::factory()->for($conversation)->create(['text' => 'а ипотека?']);
    Conversation::factory()->for($tenant)->create(['channel' => ConversationChannel::Test]);
    $this->actingAs(User::factory()->for($tenant)->create());

    $response = $this->get("/chat/{$conversation->id}");

    $response->assertInertia(fn (Assert $page) => $page
        ->component('chat/Index')
        ->where('conversation.id', $conversation->id)
        ->has('conversations', 2)
        ->where('conversations.1.title', 'двушка до 9 млн')
        ->has('messages', 3)
        ->where('messages.1.run.steps', [['tool' => 'search_flats', 'args' => ['request' => 'двушка'], 'summary' => 'Найдено квартир: 1.']])
        ->where('messages.2.run', null)
        ->where('waiting', true));
});

it('stops waiting once the agent has replied', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    Message::factory()->for($conversation)->create();
    Message::factory()->for($conversation)->fromAgent()->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->get("/chat/{$conversation->id}")->assertInertia(fn (Assert $page) => $page->where('waiting', false));
});

it('shows the news of a subscription with the button that ends it, and ends it', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    $search = SavedSearch::factory()->for($conversation)->create();
    Message::factory()->for($conversation)->fromAgent('По вашей подписке появились квартиры.')->create(['saved_search_id' => $search->id]);
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->get("/chat/{$conversation->id}")->assertInertia(fn (Assert $page) => $page
        ->where('messages.0.saved_search', ['id' => $search->id, 'active' => true]));
    $this->post("/chat/{$conversation->id}/saved-searches/{$search->id}/unsubscribe")->assertRedirect("/chat/{$conversation->id}");

    expect($search->fresh()->active)->toBeFalse();
});

it('does not end a subscription of another dialog', function () {
    $tenant = Tenant::factory()->create();
    $conversation = Conversation::factory()->for($tenant)->create();
    $other = SavedSearch::factory()->for(Conversation::factory()->for($tenant))->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post("/chat/{$conversation->id}/saved-searches/{$other->id}/unsubscribe")->assertNotFound();

    expect($other->fresh()->active)->toBeTrue();
});
