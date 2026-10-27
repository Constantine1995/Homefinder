import type { PlaceType } from '@/types/catalog';

/**
 * The hard filters as the agent service names them (agent/src/homefinder/search/filters.py).
 */
export interface SearchFilters {
    complex_ids: number[];
    rooms: number[];
    is_euro: boolean | null;
    price_from: number | null;
    price_to: number | null;
    area_from: number | null;
    area_to: number | null;
    floor_from: number | null;
    floor_to: number | null;
    not_first_floor: boolean;
    not_last_floor: boolean;
    deadline_to: string | null;
    metro_walk_to: number | null;
    nearby: PlaceType[];
    finishings: string[];
    statuses: string[];
}

export interface NearestPlace {
    name: string | null;
    distance_m: number;
    walk_min: number;
}

export interface FoundFlat {
    id: number;
    complex_id: number;
    complex_name: string;
    building_name: string;
    number: string | null;
    floor: number;
    floors: number | null;
    rooms: number;
    is_euro: boolean;
    area: number;
    kitchen_area: number | null;
    finishing: string | null;
    price: number;
    price_per_meter: number;
    deadline: string | null;
    plan_url: string | null;
    features: Record<string, unknown>;
    nearest: Partial<Record<PlaceType, NearestPlace>>;
    wish_similarity: number | null;
    reasons: string[];
}

export interface Relaxation {
    field: string;
    filters: SearchFilters;
    total: number;
    description: string;
}

export interface SearchAnswer {
    query: { filters: Partial<SearchFilters>; wishes: string | null; unknown_complexes: string[] };
    result: { total: number; flats: FoundFlat[] };
    relaxations: Relaxation[];
}

export type Search = SearchAnswer | { error: string };
