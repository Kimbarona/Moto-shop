import { usePage } from '@inertiajs/react';
import { Wrench } from 'lucide-react';
import type { AuthLayoutProps } from '@/types';

/**
 * "Pit Lane" sign-in shell: a diamond-plate brand panel with the shop logo from
 * App Settings, and the form on a plain surface. Stacks on phones.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { shop } = usePage().props;

    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <aside className="diamond-plate relative flex flex-col justify-between gap-6 overflow-hidden px-6 py-6 text-white lg:px-12 lg:py-12">
                <div className="flex items-center gap-3">
                    {shop.logo ? (
                        <img
                            src={shop.logo}
                            alt=""
                            className="h-12 w-auto rounded bg-white/90 p-1 lg:h-16"
                        />
                    ) : (
                        <span className="grid size-12 place-items-center rounded-md bg-racing text-asphalt lg:size-14">
                            <Wrench className="size-7 lg:size-8" />
                        </span>
                    )}
                    <span className="font-display text-2xl font-extrabold tracking-wide uppercase lg:text-3xl">
                        {shop.name}
                    </span>
                </div>

                <div className="hidden lg:block">
                    <p className="font-display text-5xl leading-[0.95] font-extrabold tracking-wide uppercase">
                        Parts.
                        <br />
                        Service.
                        <br />
                        <span className="text-racing">Back on the road.</span>
                    </p>
                    <div className="tread-divider mt-8 max-w-xs text-white" />
                </div>

                <p className="hidden text-sm text-zinc-400 lg:block">
                    POS · Service Board · Inventory · Reports
                </p>

                {/* Racing stripe along the edge of the panel */}
                <div className="racing-stripe pointer-events-none absolute inset-y-0 right-0 hidden w-2 bg-racing lg:block" />
                <div className="racing-stripe pointer-events-none absolute inset-x-0 bottom-0 h-1.5 bg-racing lg:hidden" />
            </aside>

            <main className="flex items-center justify-center p-6 md:p-10">
                <div className="w-full max-w-sm">
                    <div className="mb-8 space-y-2">
                        <h1 className="font-display text-3xl font-extrabold tracking-wide uppercase">
                            {title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
