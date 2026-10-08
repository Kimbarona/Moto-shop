import PosLayout from '@/layouts/pos-layout';
import type { AppLayoutProps } from '@/types';

/**
 * Account pages (profile, password, appearance) use the shop's own top bar so they
 * feel like the rest of the app.
 */
export default ({ children, breadcrumbs }: AppLayoutProps) => (
    <PosLayout title={breadcrumbs?.at(-1)?.title ?? 'Settings'}>
        {children}
    </PosLayout>
);
