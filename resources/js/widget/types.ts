/**
 * What the site widget gets from App\Http\Controllers\Widget\VisitorController.
 */
export interface WidgetChannel {
    name: string;
    introduction: string;
    policy_url: string;
}

/**
 * A flat's card under the agent's reply (App\Http\Resources\Widget\FlatCardResource).
 */
export interface WidgetFlatCard {
    id: number;
    title: string;
    place: string;
    details: string;
    plan_url: string | null;
    reserved: boolean;
}

/**
 * A message of the visitor's dialog (App\Http\Resources\Widget\MessageResource).
 */
export interface WidgetMessage {
    id: number;
    role: 'buyer' | 'agent' | 'manager';
    text: string;
    created_at: string;
    flats: WidgetFlatCard[];
    saved_search: { id: number; active: boolean } | null;
}

/**
 * The visitor's dialog as every action of App\Http\Controllers\Widget\ConversationController answers it.
 */
export interface WidgetDialog {
    channel: string;
    consented: boolean;
    status: 'ai' | 'human' | 'closed' | null;
    waiting: boolean;
    phone_requested: boolean;
    messages: WidgetMessage[];
}

export type FlatRequest = 'booking' | 'similar';
