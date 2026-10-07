import { Flag } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pos/empty-state';
import { OrderCard } from '@/components/pos/order-card';
import { Input } from '@/components/ui/input';
import PosLayout from '@/layouts/pos-layout';
import { peso } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { OrderCard as OrderCardType } from '@/types/pos';

type Filter = 'all' | 'service' | 'walk_in' | 'ready';

export default function OpenOrders({ orders }: { orders: OrderCardType[] }) {
    const [filter, setFilter] = useState<Filter>('all');
    const [q, setQ] = useState('');

    const shown = useMemo(() => {
        const term = q.trim().toLowerCase();

        return orders.filter((o) => {
            if (filter === 'service' && o.type !== 'service') {
                return false;
            }

            if (filter === 'walk_in' && o.type !== 'walk_in') {
                return false;
            }

            if (filter === 'ready' && o.service_status !== 'done') {
                return false;
            }

            return (
                !term ||
                [o.order_no, o.customer, o.plate_no, o.model].some((v) =>
                    v?.toLowerCase().includes(term),
                )
            );
        });
    }, [orders, filter, q]);

    const filters: { value: Filter; label: string; count: number }[] = [
        { value: 'all', label: 'All', count: orders.length },
        {
            value: 'ready',
            label: 'Ready for payment',
            count: orders.filter((o) => o.service_status === 'done').length,
        },
        {
            value: 'service',
            label: 'Service',
            count: orders.filter((o) => o.type === 'service').length,
        },
        {
            value: 'walk_in',
            label: 'Walk-in',
            count: orders.filter((o) => o.type === 'walk_in').length,
        },
    ];

    return (
        <PosLayout title="Open Orders" openOrdersCount={orders.length}>
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold">
                            Open orders
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {orders.length} pending ·{' '}
                            {peso(orders.reduce((sum, o) => sum + o.total, 0))}
                        </p>
                    </div>
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        {filters.map((f) => (
                            <button
                                key={f.value}
                                type="button"
                                onClick={() => setFilter(f.value)}
                                className={cn(
                                    'h-10 rounded-full border px-4 text-sm font-semibold',
                                    filter === f.value
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-card hover:border-primary',
                                )}
                            >
                                {f.label}{' '}
                                <span className="tabular opacity-70">
                                    {f.count}
                                </span>
                            </button>
                        ))}
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Plate, customer, order no."
                            className="h-10 w-60"
                        />
                    </div>
                </div>

                {shown.length === 0 ? (
                    <EmptyState
                        icon={Flag}
                        title={
                            orders.length === 0
                                ? 'No open orders — garage is clear 🏁'
                                : 'Nothing matches'
                        }
                    >
                        {orders.length === 0
                            ? 'New service orders and sales saved as Pending show up here.'
                            : 'Try another filter.'}
                    </EmptyState>
                ) : (
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-3">
                        {shown.map((order) => (
                            <OrderCard key={order.id} order={order} />
                        ))}
                    </div>
                )}
            </div>
        </PosLayout>
    );
}
