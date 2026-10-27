export type PlaceType = 'metro' | 'school' | 'kindergarten' | 'park' | 'shop';

export interface Option {
    value: string;
    label: string;
}

export interface CatalogFilters {
    complexes: number[];
    rooms: number[];
    euro: boolean | null;
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
    finishings: string[];
    statuses: string[];
}

export interface CatalogSort {
    field: string;
    direction: 'asc' | 'desc';
}

export interface FlatRow {
    id: number;
    complex_id: number;
    complex_name: string;
    building_name: string;
    number: string | null;
    floor: number;
    floors: number | null;
    rooms: number;
    is_euro: boolean;
    is_open_plan: boolean;
    area: number;
    kitchen_area: number | null;
    finishing: string | null;
    price: number;
    price_per_meter: number;
    status: string;
    status_label: string;
    deadline: string | null;
    plan_url: string | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface NearestMetro {
    name: string | null;
    distance_m: number;
    walk_min: number;
}

export interface ComplexPin {
    id: number;
    name: string;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    deadline: string | null;
    available_count: number;
    min_price: number | null;
    nearest_metro: NearestMetro | null;
}

export interface Place {
    id: number;
    type: PlaceType;
    name: string;
    distance_m: number;
    walk_min: number;
    latitude: number;
    longitude: number;
}

export interface CatalogOptions {
    finishings: Option[];
    statuses: Option[];
    deadlines: string[];
    many_rooms: number;
}
