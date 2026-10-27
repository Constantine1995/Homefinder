<script setup lang="ts">
import { computed } from 'vue';
import { formatDistance, placeTypeColors, placeTypeLabels } from '@/lib/format';
import type { ComplexPin, Place, PlaceType } from '@/types/catalog';

const props = defineProps<{
    complex: ComplexPin;
    places: Place[];
}>();

/**
 * The nearest few places of every kind; the map shows the rest.
 */
const PLACES_PER_TYPE = 3;

const groups = computed(() =>
    (Object.keys(placeTypeLabels) as PlaceType[])
        .map((type) => ({
            type,
            places: props.places.filter((place) => place.type === type).slice(0, PLACES_PER_TYPE),
            count: props.places.filter((place) => place.type === type).length,
        }))
        .filter((group) => group.count > 0),
);
</script>

<template>
    <section class="flex flex-col gap-3 rounded-[24px] border border-line p-4">
        <div class="flex flex-col gap-1">
            <h2 class="text-[16px]">{{ complex.name }}</h2>
            <p v-if="complex.address" class="text-[14px] text-muted">{{ complex.address }}</p>
        </div>
        <p v-if="groups.length === 0" class="text-[14px] text-muted">
            Окружение ещё не посчитано: у ЖК нет координат или не загружены объекты OpenStreetMap.
        </p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="group in groups" :key="group.type" class="flex flex-col gap-1">
                <h3 class="flex items-center gap-2 text-[14px]">
                    <span class="inline-block size-2 rounded-full" :style="{ background: placeTypeColors[group.type] }" />
                    {{ placeTypeLabels[group.type] }}
                    <span class="font-normal text-muted">{{ group.count }}</span>
                </h3>
                <p v-for="place in group.places" :key="place.id" class="text-[14px]">
                    {{ place.name }}
                    <span class="text-muted">· {{ formatDistance(place.distance_m) }}, {{ place.walk_min }} мин</span>
                </p>
            </div>
        </div>
    </section>
</template>
