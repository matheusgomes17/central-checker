import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Fonte de display do tema "fogo": títulos, marca e números de
                // destaque (wordmark, .top-bar h1, stat-cards), nunca corpo de texto.
                bunny('Orbitron', {
                    weights: [500, 700, 800],
                }),
                // Corpo de texto do shell autenticado do produto (sidebar, top-bar,
                // conteúdo das telas fire) — o marketing/auth continua em Instrument Sans.
                bunny('Rajdhani', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
