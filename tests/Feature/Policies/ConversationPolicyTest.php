<?php

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;

/**
 * Build the conversation in the given state for the employee: «ai», «handed off», «taken by me»,
 * «taken by another» or «closed».
 */
function conversationFor(User $employee, string $state): Conversation
{
    $factory = Conversation::factory()->for($employee->tenant);

    return match ($state) {
        'ai' => $factory->create(),
        'handed off' => $factory->handedOff()->create(),
        'taken by me' => $factory->takenBy($employee)->create(),
        'taken by another' => $factory->takenBy(User::factory()->for($employee->tenant)->create())->create(),
        'closed' => $factory->closed()->create(),
    };
}

it('lets a manager act on unassigned conversations and their own', function (string $state, array $abilities) {
    $manager = User::factory()->for(Tenant::factory())->create(['role' => UserRole::Manager]);
    $conversation = conversationFor($manager, $state);

    $allowed = array_values(array_filter(['take', 'reply', 'handBack', 'close'], fn (string $ability): bool => $manager->can($ability, $conversation)));

    expect($allowed)->toBe($abilities);
})->with([
    'answered by the agent' => ['ai', ['take', 'close']],
    'handed off' => ['handed off', ['take', 'handBack', 'close']],
    'taken by the manager' => ['taken by me', ['take', 'reply', 'handBack', 'close']],
    'taken by another manager' => ['taken by another', []],
    'closed' => ['closed', []],
]);

it('lets the sales head and the admin take over another manager\'s conversation, but not write in it', function (UserRole $role) {
    $supervisor = User::factory()->for(Tenant::factory())->create(['role' => $role]);
    $conversation = conversationFor($supervisor, 'taken by another');

    expect($supervisor->can('take', $conversation))->toBeTrue()
        ->and($supervisor->can('handBack', $conversation))->toBeTrue()
        ->and($supervisor->can('close', $conversation))->toBeTrue()
        ->and($supervisor->can('reply', $conversation))->toBeFalse();
})->with([UserRole::SalesHead, UserRole::Admin]);
