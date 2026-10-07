import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Friendly empty state with a checkered "finish line" accent. */
export function EmptyState({
    icon: Icon,
    title,
    children,
    className,
}: {
    icon: LucideIcon;
    title: string;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
                className,
            )}
        >
            <div className="tread-divider w-24 text-foreground" />
            <Icon className="size-10 text-muted-foreground" strokeWidth={1.5} />
            <p className="font-display text-xl font-bold">{title}</p>
            {children && (
                <div className="max-w-sm text-sm text-muted-foreground">
                    {children}
                </div>
            )}
        </div>
    );
}
