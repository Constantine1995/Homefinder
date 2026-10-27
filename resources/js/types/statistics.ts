import type { LeadScore } from '@/types/chat';
import type { SearchFilters } from '@/types/search';

export interface SalesStatistics {
    funnel: {
        conversations: number;
        out_of_hours: number;
        leads: number;
        scores: Record<LeadScore, number>;
        viewing_requests: number;
    };
    days: { date: string; conversations: number; out_of_hours: number }[];
    handoff_reasons: { reason: string; conversations: number }[];
    agent: { handed_off: number; turns: number; fallbacks: number; errors: number; seconds_median: number | null };
    unmet_demand: { filters: Partial<SearchFilters>; conversations: number; searches: number }[];
    knowledge_gaps: { question: string; times: number; last_asked_at: string }[];
}
