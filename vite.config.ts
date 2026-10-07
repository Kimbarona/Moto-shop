import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Front of house (React POS / Service Board)
                'resources/css/app.css',
                'resources/js/app.tsx',
                // Filament back-office theme (separate Tailwind build)
                'resources/css/filament/admin/theme.css',
            ],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
    build: {
        rollupOptions: {
            output: {
                // Long-lived vendor chunks: cached across deploys of app code.
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }

                    if (/node_modules\/(react|react-dom|scheduler)\//.test(id)) {
                        return 'vendor-react';
                    }

                    if (/node_modules\/(@inertiajs|axios|qs|laravel-precognition)/.test(id)) {
                        return 'vendor-inertia';
                    }

                    if (/node_modules\/(@radix-ui|lucide-react|sonner|@floating-ui)/.test(id)) {
                        return 'vendor-ui';
                    }

                    return 'vendor';
                },
            },
        },
    },
});
