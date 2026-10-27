import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

/**
 * The site widget: a separate build into one script, public/widget/widget.js, with the styles inside
 * (resources/js/widget/main.ts).
 */
export default defineConfig({
    plugins: [vue()],
    publicDir: false,
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
    },
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        outDir: 'public/widget',
        emptyOutDir: true,
        lib: {
            entry: 'resources/js/widget/main.ts',
            name: 'HomefinderWidget',
            formats: ['iife'],
            fileName: () => 'widget.js',
        },
    },
});
