<script setup lang="ts">
import Column from 'primevue/column';
import DataTable, { type DataTablePageEvent, type DataTableSortEvent } from 'primevue/datatable';
import Tag from 'primevue/tag';
import { formatArea, formatDeadline, formatPrice, formatRooms } from '@/lib/format';
import type { CatalogSort, FlatRow, Paginated } from '@/types/catalog';

defineProps<{
    flats: Paginated<FlatRow>;
    sort: CatalogSort;
    loading: boolean;
}>();

const emit = defineEmits<{
    page: [page: number];
    sort: [sort: CatalogSort];
}>();

const statusSeverities: Record<string, 'success' | 'warn' | 'danger' | 'secondary'> = {
    available: 'success',
    reserved: 'warn',
    sold: 'danger',
    withdrawn: 'secondary',
};

function onPage(event: DataTablePageEvent): void {
    emit('page', event.page + 1);
}

function onSort(event: DataTableSortEvent): void {
    emit('sort', { field: String(event.sortField ?? 'price'), direction: event.sortOrder === -1 ? 'desc' : 'asc' });
}
</script>

<template>
    <DataTable
        :value="flats.data"
        lazy
        paginator
        :rows="flats.per_page"
        :total-records="flats.total"
        :first="(flats.current_page - 1) * flats.per_page"
        :sort-field="sort.field"
        :sort-order="sort.direction === 'desc' ? -1 : 1"
        :loading="loading"
        data-key="id"
        size="small"
        @page="onPage"
        @sort="onSort"
    >
        <template #empty>
            <p class="py-6 text-center text-[14px] text-muted">Под эти фильтры квартир нет. Ослабьте цену, срок или комнаты.</p>
        </template>
        <Column header="ЖК, корпус">
            <template #body="{ data }: { data: FlatRow }">
                <div class="flex flex-col">
                    <span>{{ data.complex_name }}</span>
                    <span class="text-[12px] text-muted">{{ data.building_name }}<template v-if="data.number">, кв. {{ data.number }}</template></span>
                </div>
            </template>
        </Column>
        <Column header="Тип">
            <template #body="{ data }: { data: FlatRow }">{{ formatRooms(data.rooms, data.is_euro, data.is_open_plan) }}</template>
        </Column>
        <Column field="area" header="Площадь" sortable>
            <template #body="{ data }: { data: FlatRow }">
                <div class="flex flex-col">
                    <span>{{ formatArea(data.area) }}</span>
                    <span v-if="data.kitchen_area" class="text-[12px] text-muted">кухня {{ formatArea(data.kitchen_area) }}</span>
                </div>
            </template>
        </Column>
        <Column field="floor" header="Этаж" sortable>
            <template #body="{ data }: { data: FlatRow }">{{ data.floor }}<span v-if="data.floors" class="text-muted"> из {{ data.floors }}</span></template>
        </Column>
        <Column header="Отделка">
            <template #body="{ data }: { data: FlatRow }">{{ data.finishing ?? '—' }}</template>
        </Column>
        <Column field="deadline" header="Сдача" sortable>
            <template #body="{ data }: { data: FlatRow }">{{ formatDeadline(data.deadline) }}</template>
        </Column>
        <Column field="price" header="Цена" sortable>
            <template #body="{ data }: { data: FlatRow }">
                <span class="font-bold whitespace-nowrap">{{ formatPrice(data.price) }}</span>
            </template>
        </Column>
        <Column field="price_per_meter" header="За м²" sortable>
            <template #body="{ data }: { data: FlatRow }"><span class="whitespace-nowrap text-muted">{{ formatPrice(data.price_per_meter) }}</span></template>
        </Column>
        <Column header="Статус">
            <template #body="{ data }: { data: FlatRow }">
                <Tag :value="data.status_label" :severity="statusSeverities[data.status] ?? 'secondary'" rounded />
            </template>
        </Column>
        <Column header="">
            <template #body="{ data }: { data: FlatRow }">
                <a v-if="data.plan_url" :href="data.plan_url" target="_blank" rel="noopener noreferrer" class="text-[14px] underline decoration-accent decoration-2 underline-offset-4">
                    План
                </a>
            </template>
        </Column>
    </DataTable>
</template>
