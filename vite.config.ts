import { defineConfig, type UserConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import Symfony from '@symfony/reprise/vite';

// The dev server runs in the "vite" container (compose.yaml); the browser
// reaches it through the published port.
const port = Number(process.env.VITE_PORT ?? 5173);

// The application: modules, each named after its content, which the pages name through Reprise.
const app: UserConfig = {
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
};

// The script a site being reviewed loads (assets/widget/), built apart with `--mode widget`:
// one classic script without a hash in its name, since the line that loads it sits on other
// people's sites and must keep working. A build gives modules or one such script, not both,
// hence the two. It is left readable: whoever installs it may want to see what it does.
const widget: UserConfig = {
    publicDir: false,
    build: {
        outDir: 'public',
        emptyOutDir: false,
        minify: false,
        lib: {
            entry: './assets/widget/widget.js',
            formats: ['iife'],
            name: 'CairnWidget',
            fileName: () => 'widget.js',
        },
    },
};

export default defineConfig(({ mode }) => ('widget' === mode ? widget : app));
