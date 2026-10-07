import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Nunito', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Fraunces', {
                    weights: [500, 600, 700],
                }),
                bunny('Public Sans', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500, 700],
                }),
                bunny('VT323', {
                    weights: [400],
                }),
                bunny('Tinos', {
                    weights: [400, 700],
                }),
                bunny('Courier Prime', {
                    weights: [400, 700],
                }),
                bunny('Press Start 2P', {
                    weights: [400],
                }),
                bunny('DotGothic16', {
                    weights: [400],
                }),
                bunny('Share Tech Mono', {
                    weights: [400],
                }),
                bunny('Silkscreen', {
                    weights: [400, 700],
                }),
                bunny('Pixelify Sans', {
                    weights: [500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
