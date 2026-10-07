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
                bunny('JetBrains Mono', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('Public Sans', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500, 700],
                    preload: false,
                }),
                bunny('VT323', {
                    weights: [400],
                    preload: false,
                }),
                bunny('Tinos', {
                    weights: [400, 700],
                    preload: false,
                }),
                bunny('Courier Prime', {
                    weights: [400, 700],
                    preload: false,
                }),
                bunny('Press Start 2P', {
                    weights: [400],
                    preload: false,
                }),
                bunny('DotGothic16', {
                    weights: [400],
                    preload: false,
                }),
                bunny('Share Tech Mono', {
                    weights: [400],
                    preload: false,
                }),
                bunny('Silkscreen', {
                    weights: [400, 700],
                    preload: false,
                }),
                bunny('Quicksand', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('Fredoka', {
                    weights: [500, 600, 700],
                    preload: false,
                }),
                bunny('Libre Franklin', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('Poppins', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('Pixelify Sans', {
                    weights: [500, 600, 700],
                    preload: false,
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
