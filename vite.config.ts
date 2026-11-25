import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import Symfony from '@symfony/reprise/vite';

// The dev server runs in the "vite" container (compose.yaml); the browser
// reaches it through the published port.
const port = Number(process.env.VITE_PORT ?? 5173);

export default defineConfig({
    input: {
        app: './assets/app.js',
        styles: './assets/styles/app.css',
    },
    server: {
        host: true,
        port,
        strictPort: true,
        // Caches, logs and the files the tests write: nothing the pages are built from, and
        // watching them costs a core for as long as the test suite runs.
        watch: {
            ignored: ['**/var/**'],
        },
    },
    plugins: [
        tailwindcss(),
        Symfony({
            stimulus: './assets/controllers.json',
            devServerOrigin: `http://localhost:${port}`,
        }),
    ],
});
