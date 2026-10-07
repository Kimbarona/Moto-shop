import { Head } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';

/**
 * Temporary landing page for front-of-house screens that are built in later phases.
 */
export default function ComingSoon({ title }: { title: string }) {
    return (
        <AppLayout breadcrumbs={[{ title, href: '#' }]}>
            <Head title={title} />
            <div className="flex flex-1 flex-col items-center justify-center gap-3 p-10 text-center">
                <Wrench className="size-10 text-muted-foreground" />
                <h1 className="text-2xl font-bold uppercase">{title}</h1>
                <p className="text-muted-foreground">
                    This screen is still in the pit. It arrives in a later
                    build phase.
                </p>
            </div>
        </AppLayout>
    );
}
