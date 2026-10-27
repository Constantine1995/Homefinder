import type { PlaceType } from '@/types/catalog';

const wholeNumber = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });
const oneDecimal = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 });
const romanQuarters = ['I', 'II', 'III', 'IV'];

export function formatPrice(rubles: number): string {
    return `${wholeNumber.format(rubles)} ₽`;
}

export function formatMillions(rubles: number): string {
    return `${oneDecimal.format(rubles / 1_000_000)} млн ₽`;
}

export function formatArea(squareMeters: number): string {
    return `${oneDecimal.format(squareMeters)} м²`;
}

export function formatDistance(meters: number): string {
    return meters < 1000 ? `${meters} м` : `${oneDecimal.format(meters / 1000)} км`;
}

/**
 * Write the layout as sales offices do: «Студия», «2-комн.», or «2Е» for a euro layout that counts the kitchen-living room.
 */
export function formatRooms(rooms: number, isEuro: boolean, isOpenPlan: boolean): string {
    if (isOpenPlan) {
        return 'Своб. планировка';
    }

    if (rooms === 0) {
        return 'Студия';
    }

    return isEuro ? `${rooms}Е` : `${rooms}-комн.`;
}

/**
 * Write a completion date as the quarter it falls in, «IV кв. 2027».
 */
export function formatDeadline(date: string | null): string {
    if (date === null) {
        return '—';
    }

    const [year, month] = date.split('-').map(Number);

    return `${romanQuarters[Math.floor(((month ?? 12) - 1) / 3)]} кв. ${year}`;
}

export const placeTypeLabels: Record<PlaceType, string> = {
    metro: 'Метро',
    school: 'Школы',
    kindergarten: 'Детские сады',
    park: 'Парки',
    shop: 'Магазины',
};

/**
 * Colors of the places on the map, from the design system's palette.
 */
export const placeTypeColors: Record<PlaceType, string> = {
    metro: '#d93636',
    school: '#007aff',
    kindergarten: '#d0538f',
    park: '#2da334',
    shop: '#148a8e',
};
