<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/**
 * The floating header of every page: the logo, the page's own navigation and its actions on the right.
 */
defineProps<{
    /** Where the logo leads: the product page for guests, the cabinet's start inside it. */
    home: string;
}>();
</script>

<template>
    <div class="px-4 pt-4 sm:px-6">
        <header
            class="mx-auto flex max-w-[1280px] flex-wrap items-center gap-x-8 gap-y-2 rounded-[24px] border border-line bg-capsule px-6 py-3 shadow-raised lg:rounded-[40px]"
        >
            <Link :href="home" class="flex shrink-0 items-center gap-2 text-[16px] font-bold">
                <span class="size-6 rounded-[6px] border-[3px] border-lavender" aria-hidden="true" />
                Homefinder
            </Link>
            <slot />
            <div class="ml-auto flex items-center gap-4">
                <slot name="actions" />
            </div>
        </header>
    </div>
</template>
