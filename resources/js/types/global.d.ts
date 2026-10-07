import type { Auth } from '@/types/auth';

export type Shop = {
    name: string;
    logo: string | null;
    posTheme: 'dark' | 'light' | 'system';
};

export type Flash = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
};

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            shop: Shop;
            auth: Auth;
            flash: Flash;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
