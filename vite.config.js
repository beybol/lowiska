import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',
        // Port wewnętrzny musi równać się portowi hosta: adres pochodzenia
        // wstrzykiwany na stronę bierze się z tej konfiguracji, nie z mapowania
        // Compose'a.
        port: 8173,
        strictPort: true,
        hmr: {
            // Przeglądarka łączy się z hosta, a 0.0.0.0 jest w niej blokowane.
            host: 'localhost',
        },
        watch: {
            // Powiązanie katalogu z Windows nie przekazuje zdarzeń systemu
            // plików, więc zmiany trzeba wykrywać odpytywaniem. Bez wykluczeń
            // odpytywanie całego drzewa zatyka serwer.
            usePolling: true,
            // ⚠️ Odstęp 1000 ms, nie krótszy. Przy 300 ms samo odpytywanie
            // zjadało ~44% rdzenia bez przerwy, także gdy nikt nic nie edytował
            // (zmierzone przez `docker stats`). Ceną jest do sekundy opóźnienia
            // w przeładowaniu.
            interval: 1000,
            ignored: [
                '**/vendor/**',
                '**/node_modules/**',
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/.git/**',
                '**/public/build/**',
            ],
        },
    },
});
