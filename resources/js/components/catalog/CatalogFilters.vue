<script setup lang="ts">
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputNumber from 'primevue/inputnumber';
import MultiSelect from 'primevue/multiselect';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import { computed } from 'vue';
import { formatDeadline } from '@/lib/format';
import type { CatalogFilters, CatalogOptions, ComplexPin } from '@/types/catalog';

const filters = defineModel<CatalogFilters>({ required: true });

const props = defineProps<{
    complexes: ComplexPin[];
    options: CatalogOptions;
}>();

const emit = defineEmits<{ reset: [] }>();

const roomOptions = computed(() => [
    { label: 'Студия', value: 0 },
    { label: '1', value: 1 },
    { label: '2', value: 2 },
    { label: '3', value: 3 },
    { label: `${props.options.many_rooms}+`, value: props.options.many_rooms },
]);

const euroOptions = [
    { label: 'Только евро', value: true },
    { label: 'Без евро', value: false },
];

const deadlineOptions = computed(() =>
    props.options.deadlines.map((date) => ({ label: `не позже ${formatDeadline(date)}`, value: date })),
);

const metroOptions = [5, 10, 15, 20, 30].map((minutes) => ({ label: `до ${minutes} мин пешком`, value: minutes }));

/**
 * The price fields take millions of rubles, the filters keep rubles.
 */
function millions(key: 'price_from' | 'price_to') {
    return computed({
        get: () => (filters.value[key] === null ? null : filters.value[key] / 1_000_000),
        set: (value: number | null) => {
            filters.value[key] = value === null ? null : Math.round(value * 1_000_000);
        },
    });
}

const priceFrom = millions('price_from');
const priceTo = millions('price_to');
</script>

<template>
    <aside class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Жилой комплекс</span>
            <MultiSelect
                v-model="filters.complexes"
                :options="complexes"
                option-label="name"
                option-value="id"
                placeholder="Все ЖК"
                display="chip"
                fluid
            />
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Комнаты</span>
            <SelectButton
                v-model="filters.rooms"
                :options="roomOptions"
                option-label="label"
                option-value="value"
                multiple
                size="small"
            />
            <Select
                v-model="filters.euro"
                :options="euroOptions"
                option-label="label"
                option-value="value"
                placeholder="Любая планировка"
                show-clear
                size="small"
                fluid
            />
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Цена, млн ₽</span>
            <div class="grid grid-cols-2 gap-2">
                <InputNumber v-model="priceFrom" placeholder="от" :min-fraction-digits="0" :max-fraction-digits="1" :min="0" size="small" fluid />
                <InputNumber v-model="priceTo" placeholder="до" :min-fraction-digits="0" :max-fraction-digits="1" :min="0" size="small" fluid />
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Площадь, м²</span>
            <div class="grid grid-cols-2 gap-2">
                <InputNumber v-model="filters.area_from" placeholder="от" :min="0" size="small" fluid />
                <InputNumber v-model="filters.area_to" placeholder="до" :min="0" size="small" fluid />
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Этаж</span>
            <div class="grid grid-cols-2 gap-2">
                <InputNumber v-model="filters.floor_from" placeholder="от" :min="-5" :max="200" size="small" fluid />
                <InputNumber v-model="filters.floor_to" placeholder="до" :min="-5" :max="200" size="small" fluid />
            </div>
            <label class="flex items-center gap-2 text-[14px]">
                <Checkbox v-model="filters.not_first_floor" binary />
                Не первый
            </label>
            <label class="flex items-center gap-2 text-[14px]">
                <Checkbox v-model="filters.not_last_floor" binary />
                Не последний
            </label>
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Срок сдачи</span>
            <Select
                v-model="filters.deadline_to"
                :options="deadlineOptions"
                option-label="label"
                option-value="value"
                placeholder="Любой"
                show-clear
                size="small"
                fluid
            />
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">До метро</span>
            <Select
                v-model="filters.metro_walk_to"
                :options="metroOptions"
                option-label="label"
                option-value="value"
                placeholder="Неважно"
                show-clear
                size="small"
                fluid
            />
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Отделка</span>
            <MultiSelect
                v-model="filters.finishings"
                :options="options.finishings"
                option-label="label"
                option-value="value"
                placeholder="Любая"
                size="small"
                fluid
            />
        </div>

        <div class="flex flex-col gap-1">
            <span class="text-[12px] text-muted">Статус</span>
            <MultiSelect
                v-model="filters.statuses"
                :options="options.statuses"
                option-label="label"
                option-value="value"
                placeholder="Свободные"
                size="small"
                fluid
            />
        </div>

        <Button label="Сбросить фильтры" severity="secondary" variant="outlined" size="small" @click="emit('reset')" />
    </aside>
</template>
