import { cn } from '@/lib/utils';
import type { OrderStatus, ServiceStatus } from '@/types/pos';

const styles: Record<string, string> = {
    pending: 'bg-hivis text-asphalt',
    paid: 'bg-paid text-white',
    voided: 'bg-zinc-500 text-white',
    partial: 'bg-refunded text-white',
    full: 'bg-refunded text-white',
    waiting: 'bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100',
    in_progress: 'bg-sky-500 text-white',
    done: 'bg-paid text-white',
};

const labels: Record<string, string> = {
    pending: 'Pending',
    paid: 'Paid',
    voided: 'Voided',
    partial: 'Partly refunded',
    full: 'Refunded',
    waiting: 'Waiting',
    in_progress: 'In progress',
    done: 'Ready for payment',
};

export function StatusBadge({
    status,
    className,
}: {
    status: OrderStatus | ServiceStatus | 'partial' | 'full';
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded px-2 py-0.5 font-display text-xs font-bold tracking-wider',
                styles[status],
                className,
            )}
        >
            {labels[status] ?? status}
        </span>
    );
}
