<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import { computed, ref } from 'vue';
import FoundFlatCard from '@/components/search/FoundFlatCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { filterChips } from '@/lib/search';
import type { Relaxation, Search, SearchAnswer } from '@/types/search';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    text: string;
    examples: string[];
    complexes: Record<number, string>;
    search: Search | null;
}>();

const message = ref(props.text);
const loading = ref(false);

const answer = computed<SearchAnswer | null>(() => (props.search && 'result' in props.search ? props.search : null));
const error = computed(() => (props.search && 'error' in props.search ? props.search.error : null));
const chips = computed(() => (answer.value ? filterChips(answer.value.query.filters, props.complexes) : []));

function visit(query: Record<string, string>): void {
    router.get('/search', query, {
        preserveState: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

function submit(text = message.value): void {
    message.value = text;
    if (text.trim() !== '') {
        visit({ q: text });
    }
}

function ease(relaxation: Relaxation): void {
    const query: Record<string, string> = { q: props.text, filters: JSON.stringify(relaxation.filters) };
    if (answer.value?.query.wishes) {
        query.wishes = answer.value.query.wishes;
    }
    visit(query);
}
</script>

<template>
    <Head title="Поиск текстом" />
    <div class="mx-auto flex max-w-[880px] flex-col gap-6">
        <section class="flex flex-col gap-3">
            <h1 class="text-[25px]">Поиск</h1>
            <form class="flex gap-2" @submit.prevent="submit()">
                <InputText
                    v-model="message"
                    class="min-w-0 flex-1"
                    placeholder="двушка до 9 млн, до метро пешком, сдача не позже 2027"
                    aria-label="Сообщение покупателя"
                    maxlength="2000"
                />
                <Button type="submit" label="Найти" :loading="loading" />
            </form>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="example in examples"
                    :key="example"
                    type="button"
                    class="rounded-[46px] border border-line px-3 py-1 text-[14px] text-muted transition-colors duration-150 hover:text-ink"
                    @click="submit(example)"
                >
                    {{ example }}
                </button>
            </div>
        </section>

        <p v-if="error" class="rounded-[24px] border border-danger p-4 text-[14px]">{{ error }}</p>

        <template v-if="answer">
            <section class="flex flex-col gap-2">
                <h2 class="text-[16px]">Как понят запрос</h2>
                <div class="flex flex-wrap gap-2">
                    <span v-for="chip in chips" :key="chip" class="rounded-[46px] bg-accent px-3 py-1 text-[14px] text-ink">{{ chip }}</span>
                    <span v-if="answer.query.wishes" class="rounded-[46px] border border-line px-3 py-1 text-[14px]">
                        пожелания: {{ answer.query.wishes }}
                    </span>
                    <span v-if="chips.length === 0 && !answer.query.wishes" class="text-[14px] text-muted">
                        Условий не найдено — показаны самые доступные свободные квартиры.
                    </span>
                </div>
                <p v-if="answer.query.unknown_complexes.length" class="text-[14px] text-danger">
                    Таких ЖК у застройщика нет: {{ answer.query.unknown_complexes.join(', ') }}.
                </p>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-[16px]">Подходящие квартиры</h2>
                    <span class="text-[14px] text-muted">Найдено: {{ answer.result.total }}</span>
                </div>
                <FoundFlatCard v-for="flat in answer.result.flats" :key="flat.id" :flat="flat" />
                <div v-if="answer.result.total === 0" class="flex flex-col gap-3 rounded-[24px] border border-line p-4">
                    <p class="text-[14px]">Под эти условия квартир нет.</p>
                    <template v-if="answer.relaxations.length">
                        <p class="text-[14px] text-muted">Что можно ослабить:</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="relaxation in answer.relaxations"
                                :key="relaxation.field"
                                type="button"
                                class="rounded-[46px] border border-line px-4 py-2 text-[14px] transition-colors duration-150 hover:bg-accent"
                                @click="ease(relaxation)"
                            >
                                {{ relaxation.description }}
                            </button>
                        </div>
                    </template>
                </div>
            </section>
        </template>
    </div>
</template>
