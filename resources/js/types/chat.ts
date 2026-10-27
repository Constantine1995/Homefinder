export type ConversationStatus = 'ai' | 'human' | 'closed';

export type MessageRole = 'buyer' | 'agent' | 'manager';

export interface ConversationSummary {
    id: number;
    status: ConversationStatus;
    title: string | null;
    last_message_at: string | null;
}

export interface Conversation {
    id: number;
    status: ConversationStatus;
    handoff_reason: string | null;
}

/**
 * A tool the agent called in a turn, with what it told the model.
 */
export interface AgentStep {
    tool: string;
    args: Record<string, unknown>;
    summary: string;
}

/**
 * What the agent asked Laravel to do (agent/src/homefinder/agent/state.py, Action).
 */
export interface AgentAction {
    type: 'handoff' | 'knowledge_gap';
    reason: string;
}

/**
 * The agent's log of the turn that wrote a message.
 */
export interface AgentRun {
    intent: string | null;
    steps: AgentStep[];
    actions: AgentAction[];
    verified: boolean;
    problems: string[];
    attempts: number;
    prompt_tokens: number;
    completion_tokens: number;
    duration_ms: number;
    error: string | null;
}

export interface ChatMessage {
    id: number;
    role: MessageRole;
    text: string;
    created_at: string;
    run: AgentRun | null;
    /** The subscription whose news the message tells, with the button that ends it. */
    saved_search: { id: number; active: boolean } | null;
}

export type ConversationChannel = 'test' | 'telegram' | 'widget';

export type InboxFilter = 'open' | 'waiting' | 'mine' | 'closed';

export type LeadScore = 'hot' | 'warm' | 'cold';

/**
 * What the buyer told about the purchase, and the score the rule gave it (App\Models\Lead).
 */
export interface Lead {
    score: LeadScore;
    budget: number | null;
    payment: 'mortgage' | 'cash' | 'installment' | null;
    rooms: number[];
    deadline_year: number | null;
    family: string | null;
    sells_own_flat: boolean | null;
    wants_viewing: boolean;
}

/**
 * A conversation listed in the shared chat.
 */
export interface InboxConversationSummary {
    id: number;
    channel: ConversationChannel;
    status: ConversationStatus;
    title: string;
    preview: string | null;
    last_role: MessageRole | null;
    last_message_at: string | null;
    assignee: string | null;
    score: LeadScore | null;
}

/**
 * The conversation open in the shared chat, with what the employee may do in it (App\Policies\ConversationPolicy).
 */
export interface InboxConversation {
    id: number;
    channel: ConversationChannel;
    status: ConversationStatus;
    title: string;
    handoff_reason: string | null;
    assignee: { id: number; name: string } | null;
    contact: { name: string | null; username: string | null; phone: string | null } | null;
    lead: Lead | null;
    saved_searches: string[];
    can: { take: boolean; reply: boolean; hand_back: boolean; close: boolean };
}

export interface InboxMessage {
    id: number;
    role: MessageRole;
    text: string;
    author: string | null;
    created_at: string;
    delivered_at: string | null;
}
