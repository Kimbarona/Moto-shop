import { Link } from '@inertiajs/react';
import { Bike, Gauge, Phone, Wrench } from 'lucide-react';
import { EmptyState } from '@/components/pos/empty-state';
import { StatusBadge } from '@/components/pos/status-badge';
import PosLayout from '@/layouts/pos-layout';
import { urls } from '@/lib/api';
import { date, peso, qty } from '@/lib/format';
import type { OrderStatus } from '@/types/pos';

type HistoryEntry = {
    id: number;
    order_no: string;
    slip_no: string | null;
    type: string;
    status: OrderStatus;
    date: string | null;
    odometer: number | null;
    complaint: string | null;
    total: number;
    mechanics: string[];
    items: { description: string; type: string; qty: number }[];
};

type Bike = {
    id: number;
    plate_no: string | null;
    model: string | null;
    year: number | null;
    color: string | null;
    last_odometer: number | null;
    history: HistoryEntry[];
};

export default function CustomerShow({
    customer,
    motorcycles,
    otherOrders,
}: {
    customer: {
        id: number;
        name: string;
        mobile: string | null;
        address: string | null;
        notes: string | null;
        since: string | null;
        total_spent: number;
    };
    motorcycles: Bike[];
    otherOrders: HistoryEntry[];
}) {
    return (
        <PosLayout title={customer.name}>
            <div className="mx-auto w-full max-w-5xl space-y-4 p-4">
                <div className="rounded-xl border bg-card p-4">
                    <h1 className="font-display text-3xl font-extrabold">
                        {customer.name}
                    </h1>
                    <p className="flex flex-wrap gap-x-4 text-sm text-muted-foreground">
                        {customer.mobile && (
                            <span>
                                <Phone className="inline size-3.5" />{' '}
                                {customer.mobile}
                            </span>
                        )}
                        {customer.address && <span>{customer.address}</span>}
                        <span>Customer since {date(customer.since)}</span>
                        <span>
                            Total spent{' '}
                            <span className="tabular font-semibold text-foreground">
                                {peso(customer.total_spent)}
                            </span>
                        </span>
                    </p>
                    {customer.notes && (
                        <p className="mt-2 text-sm italic">{customer.notes}</p>
                    )}
                </div>

                {motorcycles.map((bike) => (
                    <section
                        key={bike.id}
                        className="rounded-xl border bg-card"
                    >
                        <header className="flex flex-wrap items-center gap-3 border-b p-4">
                            <Bike className="size-6 text-primary" />
                            <span className="font-display text-2xl font-extrabold">
                                {bike.plate_no ?? 'No plate'}
                            </span>
                            <span className="text-muted-foreground">
                                {[bike.model, bike.year, bike.color]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                            {bike.last_odometer !== null && (
                                <span className="ml-auto flex items-center gap-1 text-sm">
                                    <Gauge className="size-4" />{' '}
                                    {qty(bike.last_odometer)} km
                                </span>
                            )}
                        </header>
                        <History entries={bike.history} />
                    </section>
                ))}

                {otherOrders.length > 0 && (
                    <section className="rounded-xl border bg-card">
                        <header className="border-b p-4 font-display text-xl font-bold">
                            Other purchases
                        </header>
                        <History entries={otherOrders} />
                    </section>
                )}
            </div>
        </PosLayout>
    );
}

function History({ entries }: { entries: HistoryEntry[] }) {
    if (entries.length === 0) {
        return (
            <EmptyState
                icon={Wrench}
                title="No service history yet"
                className="py-6"
            />
        );
    }

    return (
        <ol className="divide-y">
            {entries.map((entry) => (
                <li
                    key={entry.id}
                    className="grid gap-2 p-4 sm:grid-cols-[150px_1fr_auto]"
                >
                    <div>
                        <p className="font-semibold">{date(entry.date)}</p>
                        {entry.odometer !== null && (
                            <p className="tabular text-xs text-muted-foreground">
                                {qty(entry.odometer)} km
                            </p>
                        )}
                    </div>
                    <div>
                        {entry.complaint && (
                            <p className="text-sm italic">
                                “{entry.complaint}”
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            {entry.items
                                .map(
                                    (i) =>
                                        `${i.description}${i.qty !== 1 ? ` × ${qty(i.qty)}` : ''}`,
                                )
                                .join(' · ')}
                        </p>
                        {entry.mechanics.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                <Wrench className="inline size-3" />{' '}
                                {entry.mechanics.join(', ')}
                            </p>
                        )}
                    </div>
                    <div className="text-right">
                        <Link
                            href={urls.order(entry.id)}
                            className="font-semibold text-primary hover:underline"
                        >
                            {entry.slip_no ?? entry.order_no}
                        </Link>
                        <p className="tabular font-display text-lg font-bold">
                            {peso(entry.total)}
                        </p>
                        <StatusBadge status={entry.status} />
                    </div>
                </li>
            ))}
        </ol>
    );
}
