import { formatArea, formatDeadline, formatMillions } from '@/lib/format';
import type { PlaceType } from '@/types/catalog';
import type { SearchFilters } from '@/types/search';

const nearbyLabels: Record<PlaceType, string> = {
    metro: 'метро',
    school: 'школа',
    kindergarten: 'детский сад',
    park: 'парк',
    shop: 'магазин',
};

const finishingLabels: Record<string, string> = {
    rough: 'без отделки',
    white_box: 'white box',
    finished: 'чистовая',
    furnished: 'под ключ',
};

function roomsLabel(rooms: number): string {
    return rooms === 0 ? 'студия' : rooms >= 4 ? '4+ комн.' : `${rooms}-комн.`;
}

/**
 * Put into words what the service understood from a message, one chip per filter, for the employee to check.
 */
export function filterChips(filters: Partial<SearchFilters>, complexes: Record<number, string>): string[] {
    const chips: string[] = [];

    for (const id of filters.complex_ids ?? []) {
        chips.push(`ЖК «${complexes[id] ?? id}»`);
    }
    if (filters.rooms?.length) {
        chips.push(filters.rooms.map(roomsLabel).join(' или '));
    }
    if (filters.is_euro != null) {
        chips.push(filters.is_euro ? 'евроформат' : 'не евро');
    }
    if (filters.price_from != null || filters.price_to != null) {
        const from = filters.price_from != null ? `от ${formatMillions(filters.price_from)}` : '';
        const to = filters.price_to != null ? `до ${formatMillions(filters.price_to)}` : '';
        chips.push([from, to].filter(Boolean).join(' '));
    }
    if (filters.area_from != null || filters.area_to != null) {
        const from = filters.area_from != null ? `от ${formatArea(filters.area_from)}` : '';
        const to = filters.area_to != null ? `до ${formatArea(filters.area_to)}` : '';
        chips.push([from, to].filter(Boolean).join(' '));
    }
    if (filters.floor_from != null || filters.floor_to != null) {
        chips.push(['этаж', filters.floor_from != null ? `от ${filters.floor_from}` : '', filters.floor_to != null ? `до ${filters.floor_to}` : ''].filter(Boolean).join(' '));
    }
    if (filters.not_first_floor) {
        chips.push('не первый этаж');
    }
    if (filters.not_last_floor) {
        chips.push('не последний этаж');
    }
    if (filters.deadline_to) {
        chips.push(`сдача до ${formatDeadline(filters.deadline_to)}`);
    }
    if (filters.metro_walk_to != null) {
        chips.push(`до метро ${filters.metro_walk_to} мин пешком`);
    }
    for (const type of filters.nearby ?? []) {
        chips.push(`рядом ${nearbyLabels[type]}`);
    }
    if (filters.finishings?.length) {
        chips.push(filters.finishings.map((finishing) => finishingLabels[finishing] ?? finishing).join(' или '));
    }

    return chips;
}
