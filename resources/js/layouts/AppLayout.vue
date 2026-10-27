<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { computed } from 'vue';
import HeaderBar from '@/components/HeaderBar.vue';

interface SharedProps {
    auth: {
        user: { id: number; tenant_id: number; name: string; email: string } | null;
        can: { view_statistics: boolean };
    };
    [key: string]: unknown;
}

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth.user);
const seesStatistics = computed(() => page.props.auth.can.view_statistics);

const sections = computed(() => [
    { href: '/catalog', label: 'Каталог' },
    { href: '/search', label: 'Поиск текстом' },
    { href: '/inbox', label: 'Диалоги' },
    { href: '/chat', label: 'Тестовый чат' },
    ...(seesStatistics.value ? [{ href: '/statistics', label: 'Статистика' }] : []),
]);
</script>

<template>
    <div class="min-h-screen bg-page">
        <HeaderBar home="/catalog">
            <nav class="order-last flex w-full items-center gap-2 overflow-x-auto lg:order-none lg:w-auto">
                <Link
                    v-for="section in sections"
                    :key="section.href"
                    :href="section.href"
                    class="shrink-0 rounded-[46px] px-4 py-2 text-[14px] transition-colors duration-150"
                    :class="page.url.startsWith(section.href) ? 'bg-accent text-white' : 'text-muted hover:text-ink'"
                >
                    {{ section.label }}
                </Link>
            </nav>
            <template v-if="user" #actions>
                <span class="hidden text-[14px] text-muted lg:inline">{{ user.name }}</span>
                <Link href="/logout" method="post" as="div">
                    <Button label="Выйти" severity="secondary" variant="outlined" size="small" />
                </Link>
            </template>
        </HeaderBar>
        <main class="mx-auto max-w-[1280px] px-4 py-6 sm:px-6">
            <slot />
        </main>
    </div>
</template>
