<script setup lang="ts">
import { Map as MapLibre, Marker, NavigationControl, LngLatBounds, Popup, setWorkerUrl, type GeoJSONSource } from 'maplibre-gl';
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import type { FeatureCollection, Point } from 'geojson';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatDistance, formatMillions, placeTypeColors, placeTypeLabels } from '@/lib/format';
import type { ComplexPin, Place, PlaceType } from '@/types/catalog';

const props = defineProps<{
    complexes: ComplexPin[];
    places: Place[];
    selectedId: number | null;
}>();

const emit = defineEmits<{ select: [complexId: number] }>();

/**
 * OpenFreeMap serves OpenStreetMap tiles without a key; its style credits OpenStreetMap, as the ODbL requires.
 */
const STYLE_URL = 'https://tiles.openfreemap.org/styles/dark';

// MapLibre looks for its worker next to its own module, which the bundle moves; the worker goes as a separate asset.
setWorkerUrl(workerUrl);

const container = ref<HTMLDivElement | null>(null);
let map: MapLibre | null = null;
const markers = new Map<number, HTMLButtonElement>();

function located(complexes: ComplexPin[]): (ComplexPin & { latitude: number; longitude: number })[] {
    return complexes.filter((complex): complex is ComplexPin & { latitude: number; longitude: number } =>
        complex.latitude !== null && complex.longitude !== null,
    );
}

/**
 * Build the pin of a complex out of text nodes only: names come from the developer's feed.
 */
function pin(complex: ComplexPin): HTMLButtonElement {
    const element = document.createElement('button');
    element.type = 'button';
    element.className =
        'group flex flex-col items-start rounded-[12px] border border-line bg-capsule px-3 py-1 text-left shadow-raised transition-colors duration-150 hover:bg-chip data-selected:bg-accent';
    const name = document.createElement('span');
    name.className = 'text-[12px] font-bold text-ink';
    name.textContent = complex.name;
    const price = document.createElement('span');
    price.className = 'text-[12px] text-muted group-data-selected:text-ink';
    price.textContent = complex.min_price === null ? 'нет свободных' : `от ${formatMillions(complex.min_price)}`;
    element.append(name, price);
    element.addEventListener('click', (event) => {
        event.stopPropagation();
        emit('select', complex.id);
    });

    return element;
}

function placesData(places: Place[]): FeatureCollection<Point> {
    return {
        type: 'FeatureCollection',
        features: places.map((place) => ({
            type: 'Feature',
            geometry: { type: 'Point', coordinates: [place.longitude, place.latitude] },
            properties: { type: place.type, name: place.name, distance: formatDistance(place.distance_m), walk: place.walk_min },
        })),
    };
}

function highlight(selectedId: number | null): void {
    markers.forEach((element, complexId) => {
        element.toggleAttribute('data-selected', complexId === selectedId);
    });
}

function frame(): void {
    const complexes = located(props.complexes);
    const selected = complexes.find((complex) => complex.id === props.selectedId);

    if (map === null || complexes.length === 0) {
        return;
    }

    if (selected !== undefined) {
        map.flyTo({ center: [selected.longitude, selected.latitude], zoom: 14 });

        return;
    }

    const bounds = new LngLatBounds();
    complexes.forEach((complex) => bounds.extend([complex.longitude, complex.latitude]));
    map.fitBounds(bounds, { padding: 64, maxZoom: 14, duration: 0 });
}

onMounted(() => {
    if (container.value === null) {
        return;
    }

    map = new MapLibre({
        container: container.value,
        style: STYLE_URL,
        center: [37.62, 55.75],
        zoom: 9,
        attributionControl: { compact: true },
    });
    map.addControl(new NavigationControl({ showCompass: false }), 'top-right');

    located(props.complexes).forEach((complex) => {
        const element = pin(complex);
        markers.set(complex.id, element);
        new Marker({ element, anchor: 'bottom' }).setLngLat([complex.longitude, complex.latitude]).addTo(map as MapLibre);
    });
    highlight(props.selectedId);

    map.on('load', () => {
        if (map === null) {
            return;
        }

        map.addSource('places', { type: 'geojson', data: placesData(props.places) });
        map.addLayer({
            id: 'places',
            type: 'circle',
            source: 'places',
            paint: {
                'circle-radius': 6,
                'circle-stroke-width': 2,
                'circle-stroke-color': '#12101c',
                'circle-color': ['match', ['get', 'type'], ...Object.entries(placeTypeColors).flat(), '#a7a3b8'] as never,
            },
        });
        map.on('click', 'places', (event) => {
            const feature = event.features?.[0];

            if (map === null || feature === undefined || feature.geometry.type !== 'Point') {
                return;
            }

            const properties = feature.properties as { type: PlaceType; name: string; distance: string; walk: number };
            new Popup({ closeButton: false })
                .setLngLat(feature.geometry.coordinates as [number, number])
                .setText(`${properties.name} · ${placeTypeLabels[properties.type]} · ${properties.distance}, ${properties.walk} мин`)
                .addTo(map);
        });
        map.on('mouseenter', 'places', () => map?.getCanvas().style.setProperty('cursor', 'pointer'));
        map.on('mouseleave', 'places', () => map?.getCanvas().style.removeProperty('cursor'));
        frame();
    });
});

watch(
    () => props.places,
    (places) => (map?.getSource('places') as GeoJSONSource | undefined)?.setData(placesData(places)),
);

watch(
    () => props.selectedId,
    (selectedId) => {
        highlight(selectedId);
        frame();
    },
);

onBeforeUnmount(() => {
    map?.remove();
    map = null;
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <div ref="container" class="h-[360px] w-full overflow-hidden rounded-[24px] border border-line" />
        <div class="flex flex-wrap gap-4 text-[12px] text-muted">
            <span v-for="(color, type) in placeTypeColors" :key="type" class="flex items-center gap-1">
                <span class="inline-block size-2 rounded-full" :style="{ background: color }" />
                {{ placeTypeLabels[type] }}
            </span>
        </div>
    </div>
</template>
