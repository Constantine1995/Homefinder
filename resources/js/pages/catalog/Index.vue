<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CatalogFilters from '@/components/catalog/CatalogFilters.vue';
import ComplexMap from '@/components/catalog/ComplexMap.vue';
import FlatTable from '@/components/catalog/FlatTable.vue';
import SurroundingsList from '@/components/catalog/SurroundingsList.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { CatalogFilters as Filters, CatalogOptions, CatalogSort, ComplexPin, FlatRow, Paginated, Place } from '@/types/catalog';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    filters: Filters;
    sort: CatalogSort;
    flats: Paginated<FlatRow>;
    complexes: ComplexPin[];
    surroundings: Place[];
    options: CatalogOptions;
}>();

const DEFAULT_FILTERS: Filters = {
    complexes: [],
    rooms: [],
    euro: null,
    price_from: null,
    price_to: null,
    area_from: null,
    area_to: null,
    floor_from: null,
    floor_to: null,
    not_first_floor: false,
    not_last_floor: false,
    deadline_to: null,
    metro_walk_to: null,
    finishings: [],
    statuses: ['available'],
};

/**
 * Wait this long after the last keystroke in a number field before asking the server again.
 */
const FILTER_DELAY_MS = 300;

/**
 * Copy the filters with their lists, so that editing the form never touches the props; props are reactive proxies,
 * which structuredClone() cannot copy.
 */
function copyFilters(source: Filters): Filters {
    return {
        ...source,
        complexes: [...source.complexes],
        rooms: [...source.rooms],
        finishings: [...source.finishings],
        statuses: [...source.statuses],
    };
}

const filters = ref<Filters>(copyFilters(props.filters));
const loading = ref(false);
let reloadTimer: ReturnType<typeof setTimeout> | undefined;

const selectedComplex = computed(() =>
    filters.value.complexes.length === 1 ? props.complexes.find((complex) => complex.id === filters.value.complexes[0]) ?? null : null,
);

/**
 * Turn the filters into a query string the catalog request validates: booleans as 1 and 0, empty values left out.
 */
type QueryValue = string | number | number[] | string[];

function query(page = 1, sort: CatalogSort = props.sort): Record<string, QueryValue> {
    const values: Record<string, QueryValue> = { sort: sort.field, direction: sort.direction };

    for (const [key, value] of Object.entries(filters.value)) {
        if (value === null || value === false || (Array.isArray(value) && value.length === 0)) {
            continue;
        }

        values[key] = typeof value === 'boolean' ? Number(value) : value;
    }

    if (page > 1) {
        values.page = page;
    }

    return values;
}

function reload(page = 1, sort: CatalogSort = props.sort): void {
    clearTimeout(reloadTimer);
    router.get('/catalog', query(page, sort), {
        only: ['filters', 'sort', 'flats', 'surroundings'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

watch(
    filters,
    () => {
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(() => reload(), FILTER_DELAY_MS);
    },
    { deep: true },
);

function selectComplex(complexId: number): void {
    filters.value.complexes = selectedComplex.value?.id === complexId ? [] : [complexId];
}

function resetFilters(): void {
    filters.value = copyFilters(DEFAULT_FILTERS);
}
</script>

<template>
    <Head title="Каталог" />
    <div class="grid gap-6 lg:grid-cols-[288px_minmax(0,1fr)]">
        <CatalogFilters v-model="filters" :complexes="complexes" :options="options" @reset="resetFilters" />

        <div class="flex min-w-0 flex-col gap-6">
            <ComplexMap
                :complexes="complexes"
                :places="surroundings"
                :selected-id="selectedComplex?.id ?? null"
                @select="selectComplex"
            />

            <SurroundingsList v-if="selectedComplex" :complex="selectedComplex" :places="surroundings" />

            <section class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-4">
                    <h1 class="text-[25px]">Квартиры</h1>
                    <span class="text-[14px] text-muted">Найдено: {{ flats.total }}</span>
                </div>
                <FlatTable :flats="flats" :sort="sort" :loading="loading" @page="reload" @sort="(sort) => reload(1, sort)" />
            </section>
        </div>
    </div>
</template>
