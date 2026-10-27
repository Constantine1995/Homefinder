import { usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';

interface ConversationUpdated {
    conversation_id: number;
}

/**
 * Call back whenever a conversation of the employee's developer changes: a new message, or another side answers.
 * The event carries only the conversation's ID; the page reloads what it shows (App\Events\ConversationUpdated).
 */
export function onConversationUpdated(callback: (conversationId: number) => void): void {
    const page = usePage<{ auth: { user: { tenant_id: number } } }>();

    useEcho<ConversationUpdated>(`tenants.${page.props.auth.user.tenant_id}.conversations`, '.conversation.updated', (event) =>
        callback(event.conversation_id),
    );
}
