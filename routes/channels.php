<?php

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Every employee of a developer follows its shared chat (ConversationUpdated); what a conversation shows is decided
// when the page fetches it.
Broadcast::channel('tenants.{tenantId}.conversations', fn (User $user, int $tenantId): bool => $user->tenant_id === $tenantId, ['guards' => ['web']]);

// A visitor of the site widget follows its own dialog, by the token the widget keeps.
Broadcast::channel('widget.contacts.{contactId}', fn (Contact $visitor, int $contactId): bool => $visitor->id === $contactId, ['guards' => ['widget']]);
