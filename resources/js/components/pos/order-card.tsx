import { Link, usePage } from '@inertiajs/react';
import { Bike, Clock, Wrench } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/pos/status-badge';
import { urls } from '@/lib/api';
import { age, hoursSince, peso } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { OrderCard as OrderCardType } from '@/types/pos';

/** Where an open order opens: the POS for cashiers / admins, the job sheet for mechanics. */
export function useOrderHref() {
    const { auth } = usePage().props;

    return (id: number) => (auth.can.usePos ? urls.pos(id) : urls.job(id));
}

export function OrderCard({
    order,
    footer,
    draggable,
    onDragStart,
}: {
    order: OrderCardType;
    footer?: ReactNode;
    draggable?: boolean;
    onDragStart?: (e: React.DragEvent) => void;
}) {
    const href = useOrderHref();
    const old = hoursSince(order.created_at) >= 24;

    return (
        <div
            draggable={draggable}
            onDragStart={onDragStart}
            className={cn(
                'group rounded-xl border bg-card shadow-sm transition hover:border-primary hover:shadow-md',
                old && 'border-l-4 border-l-hivis',
                draggable && 'cursor-grab active:cursor-grabbing',
            )}
        >
            <Link href={href(order.id)} className="block space-y-1.5 p-3">
                <div className="flex items-center justify-between gap-2">
                    <span className="font-display text-2xl leading-none font-extrabold">
                        {order.plate_no ??
                            (order.type === 'walk_in' ? 'Walk-in' : 'No plate')}
                    </span>
                    {order.service_status ? (
                        <StatusBadge status={order.service_status} />
                    ) : (
                        <StatusBadge status="pending" />
                    )}
                </div>
                <p className="truncate text-sm">
                    <span className="font-semibold">
                        {order.customer ?? 'No customer'}
                    </span>
                    {order.model && (
                        <span className="text-muted-foreground">
                            {' '}
                            · <Bike className="inline size-3.5" /> {order.model}
                        </span>
                    )}
                </p>
                {order.complaint && (
                    <p className="line-clamp-2 text-xs text-muted-foreground italic">
                        “{order.complaint}”
                    </p>
                )}
                <div className="flex items-center justify-between gap-2 pt-1 text-xs text-muted-foreground">
                    <span
                        className={cn(
                            'flex items-center gap-1',
                            old && 'font-bold text-amber-600 dark:text-hivis',
                        )}
                    >
                        <Clock className="size-3.5" /> {age(order.created_at)}
                    </span>
                    {order.mechanics.length > 0 && (
                        <span className="flex items-center gap-1 truncate">
                            <Wrench className="size-3.5" />{' '}
                            {order.mechanics.join(', ')}
                        </span>
                    )}
                </div>
                <div className="flex items-end justify-between border-t pt-1.5">
                    <span className="text-xs text-muted-foreground">
                        {order.order_no} · {order.items_count} item(s)
                    </span>
                    <span className="tabular font-display text-xl font-bold text-primary">
                        {peso(order.total)}
                    </span>
                </div>
            </Link>
            {footer}
        </div>
    );
}
