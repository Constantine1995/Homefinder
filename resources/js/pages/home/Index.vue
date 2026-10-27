<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import HeaderBar from '@/components/HeaderBar.vue';

interface SharedProps {
    auth: { user: { id: number; name: string } | null };
    [key: string]: unknown;
}

const props = defineProps<{
    example: string;
}>();

interface Feature {
    title: string;
    text: string;
    /** The colour of the card's top edge and its icon. */
    tone: 'accent' | 'indigo' | 'success' | 'amber';
    /** SVG path data of a 24×24 stroke icon. */
    icon: string;
}

const FEATURES: Feature[] = [
    {
        title: 'Подбор по шахматке',
        text: 'Понимает «двушка до 9 лямов, до метро пешком» и показывает 3–5 квартир, которые продаются прямо сейчас.',
        tone: 'accent',
        icon: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-4-4',
    },
    {
        title: 'Ипотека без ошибок',
        text: 'Платёж по программам застройщика и банков-партнёров считает код, а не модель. Расчёт помечен как предварительный.',
        tone: 'indigo',
        icon: 'M6 3h12v18H6zM9 7h6M9 11h1M14 11h1M9 15h1M14 15h1M9 18h6',
    },
    {
        title: 'Отвечает только по фактам',
        text: 'Сроки сдачи, паркинг, документы — из базы знаний со ссылкой на источник. Выдуманные цены не проходят проверку.',
        tone: 'success',
        icon: 'M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6zM9 12l2 2 4-4',
    },
    {
        title: 'Тёплый лид отделу продаж',
        text: 'Бюджет, ипотека, сроки и состав семьи — в карточке лида. Торг и сложные вопросы ИИ сразу передаёт менеджеру.',
        tone: 'amber',
        icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c0-4 4-6 8-6s8 2 8 6',
    },
];

const TONES: Record<Feature['tone'], { edge: string; tile: string }> = {
    accent: { edge: 'border-t-accent', tile: 'bg-accent/20 text-lavender' },
    indigo: { edge: 'border-t-indigo', tile: 'bg-indigo/20 text-indigo' },
    success: { edge: 'border-t-success', tile: 'bg-success/20 text-success' },
    amber: { edge: 'border-t-amber', tile: 'bg-amber/20 text-amber' },
};

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);

const request = ref('');

/**
 * Search the catalog by the buyer's words; the placeholder's example stands in for an empty box.
 */
function search(): void {
    router.get('/search', { q: request.value.trim() || props.example });
}
</script>

<template>
    <Head title="ИИ-менеджер продаж для застройщика" />
    <div class="min-h-screen bg-page text-ink">
        <HeaderBar home="/">
            <nav class="hidden items-center gap-8 text-[16px] text-muted lg:flex">
                <a href="#features" class="transition-colors duration-150 hover:text-ink">Возможности</a>
                <Link href="/search" class="transition-colors duration-150 hover:text-ink">Поиск текстом</Link>
                <a href="/demo/site" class="transition-colors duration-150 hover:text-ink">Виджет на сайте</a>
            </nav>
            <template #actions>
                <Link
                    :href="user ? '/catalog' : '/login'"
                    class="rounded-[46px] bg-accent-deep px-6 py-3 text-[16px] font-medium text-white transition-colors duration-150 hover:bg-accent"
                >
                    {{ user ? 'Открыть кабинет' : 'Войти' }}
                </Link>
            </template>
        </HeaderBar>

        <main class="hero-glow px-4 pt-24 pb-24 sm:px-6">
            <section class="mx-auto flex max-w-[1024px] flex-col items-center text-center">
                <p
                    class="inline-flex items-center gap-2 rounded-[46px] border border-line bg-capsule px-4 py-2 text-[14px] text-muted"
                >
                    <span class="text-accent" aria-hidden="true">✦</span>
                    Шахматка застройщика + база знаний + отдел продаж — в одном чате
                </p>

                <h1 class="mt-8 text-[40px] tracking-[-0.02em] sm:text-[64px]">
                    <span class="block text-muted">Отвечает покупателям,</span>
                    <span class="lavender-text block">пока отдел продаж спит</span>
                </h1>

                <p class="mt-6 max-w-[640px] text-[18px] text-muted">
                    ИИ-менеджер подбирает квартиры из актуальной шахматки, считает ипотеку и передаёт отделу продаж
                    готовый лид — в Telegram и на сайте, круглосуточно.
                </p>

                <form
                    class="mt-12 flex w-full max-w-[672px] flex-col gap-2 rounded-[24px] border border-line bg-capsule p-2 sm:flex-row sm:rounded-[20px]"
                    @submit.prevent="search"
                >
                    <label for="request" class="sr-only">Что ищет покупатель</label>
                    <input
                        id="request"
                        v-model="request"
                        type="text"
                        maxlength="2000"
                        :placeholder="example"
                        class="min-w-0 flex-1 bg-transparent px-4 py-3 text-[16px] text-ellipsis text-ink placeholder:text-muted focus:outline-none"
                    />
                    <button
                        type="submit"
                        class="flex items-center justify-center gap-2 rounded-[12px] bg-accent px-6 py-3 text-[16px] font-medium text-white transition-colors duration-150 hover:bg-accent-deep focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lavender"
                    >
                        Подобрать квартиры
                        <span aria-hidden="true">→</span>
                    </button>
                </form>

                <nav class="mt-6 flex flex-wrap justify-center gap-4 text-[14px]" aria-label="Демо">
                    <Link
                        href="/search"
                        class="inline-flex items-center gap-2 rounded-[46px] border border-accent bg-accent/20 px-4 py-2 text-ink"
                    >
                        <span class="text-lavender" aria-hidden="true">✦</span>
                        Поиск текстом
                    </Link>
                    <Link
                        href="/catalog"
                        class="inline-flex items-center gap-2 rounded-[46px] border border-line bg-capsule px-4 py-2 text-muted transition-colors duration-150 hover:text-ink"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 21s-7-6-7-12a7 7 0 0 1 14 0c0 6-7 12-7 12zM12 11a2 2 0 1 0 0-4 2 2 0 0 0 0 4z" />
                        </svg>
                        Каталог с картой
                    </Link>
                    <a
                        href="/demo/site"
                        class="inline-flex items-center gap-2 rounded-[46px] border border-line bg-capsule px-4 py-2 text-muted transition-colors duration-150 hover:text-ink"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18" />
                        </svg>
                        Виджет на сайте
                    </a>
                </nav>
            </section>

            <section
                id="features"
                class="mx-auto mt-16 grid max-w-[1024px] scroll-mt-8 gap-4 sm:grid-cols-2 lg:grid-cols-4"
                aria-label="Возможности"
            >
                <article
                    v-for="feature in FEATURES"
                    :key="feature.title"
                    class="flex flex-col gap-4 rounded-[16px] border border-t-2 border-line bg-capsule p-6"
                    :class="TONES[feature.tone].edge"
                >
                    <span class="flex size-10 items-center justify-center rounded-[12px]" :class="TONES[feature.tone].tile">
                        <svg
                            class="size-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path :d="feature.icon" />
                        </svg>
                    </span>
                    <h2 class="text-[16px]">{{ feature.title }}</h2>
                    <p class="text-[14px] text-muted">{{ feature.text }}</p>
                </article>
            </section>
        </main>
    </div>
</template>

<style scoped>
/* A violet glow behind the headline, fading into the page. */
.hero-glow {
    background: radial-gradient(60% 40% at 50% 20%, color-mix(in srgb, var(--color-accent) 22%, transparent), transparent);
}

.lavender-text {
    background: linear-gradient(90deg, var(--color-lavender), var(--color-accent));
    background-clip: text;
    color: transparent;
}
</style>
