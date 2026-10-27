<script setup lang="ts">
import { formatArea, formatDeadline, formatDistance, formatPrice, formatRooms } from '@/lib/format';
import type { FoundFlat } from '@/types/search';

defineProps<{ flat: FoundFlat }>();
</script>

<template>
    <article class="flex flex-col gap-3 rounded-[24px] border border-line p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="flex flex-col">
                <h3 class="text-[16px]">{{ formatRooms(flat.rooms, flat.is_euro, false) }}, {{ formatArea(flat.area) }}</h3>
                <span class="text-[14px] text-muted">
                    {{ flat.complex_name }}, {{ flat.building_name }}<template v-if="flat.number">, кв. {{ flat.number }}</template>
                </span>
            </div>
            <span class="text-[16px] font-bold whitespace-nowrap">{{ formatPrice(flat.price) }}</span>
        </div>
        <p class="text-[14px] text-muted">
            {{ flat.floor }}<template v-if="flat.floors"> из {{ flat.floors }}</template> этаж · сдача {{ formatDeadline(flat.deadline) }}
            <template v-if="flat.nearest.metro">
                · м. {{ flat.nearest.metro.name ?? 'метро' }}, {{ formatDistance(flat.nearest.metro.distance_m) }}, {{ flat.nearest.metro.walk_min }} мин
            </template>
        </p>
        <ul v-if="flat.reasons.length" class="flex flex-wrap gap-2">
            <li v-for="reason in flat.reasons" :key="reason" class="rounded-[46px] bg-capsule px-3 py-1 text-[14px]">
                <span class="text-success">✓</span> {{ reason }}
            </li>
        </ul>
        <div class="flex items-center justify-between gap-4 text-[14px]">
            <span v-if="flat.wish_similarity !== null" class="text-muted">
                Близость к пожеланиям: {{ Math.round(flat.wish_similarity * 100) }}%
            </span>
            <a
                v-if="flat.plan_url"
                :href="flat.plan_url"
                target="_blank"
                rel="noopener noreferrer"
                class="ml-auto underline decoration-accent decoration-2 underline-offset-4"
            >
                Планировка
            </a>
        </div>
    </article>
</template>
