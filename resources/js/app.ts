import { createInertiaApp } from '@inertiajs/vue3';
import PrimeVue from 'primevue/config';
import { createApp, h, type DefineComponent } from 'vue';
import { connectEcho } from '@/lib/echo';
import { DARK_MODE_CLASS, HomefinderPreset, russianLocale } from '@/lib/theme';

connectEcho();

createInertiaApp({
    title: (title) => (title ? `${title} — Homefinder` : 'Homefinder'),
    resolve: (name) => {
        const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
        const page = pages[`./pages/${name}.vue`];

        if (page === undefined) {
            throw new Error(`Page ${name} does not exist.`);
        }

        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(PrimeVue, {
                theme: {
                    preset: HomefinderPreset,
                    options: {
                        darkModeSelector: `.${DARK_MODE_CLASS}`,
                        cssLayer: {
                            name: 'primevue',
                            order: 'theme, base, primevue, components, utilities',
                        },
                    },
                },
                locale: russianLocale,
            })
            .mount(el);
    },
    progress: {
        color: '#d9c8ff',
    },
});
