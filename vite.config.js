import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const clientPort = Number(env.VITE_PORT || 5173);

    return {
        plugins: [
            laravel({ input: ['resources/js/app.js'], refresh: true }),
            vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: `http://localhost:${clientPort}`,
            hmr: { host: 'localhost', clientPort },
            cors: { origin: [env.APP_URL || 'http://localhost:8080', `http://127.0.0.1:${env.WEB_PORT || 8080}`] },
            watch: {
                usePolling: env.VITE_USE_POLLING === 'true',
                interval: 500,
                ignored: ['**/storage/**', '**/work/**', '**/docs/**', '**/vendor/**'],
            },
        },
    };
});
