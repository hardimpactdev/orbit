import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts', 'resources/css/app.css'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
            vue: 'vue/dist/vue.esm-bundler.js',
        },
    },
    server: {
        host: 'localhost',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
            port: 5173,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
