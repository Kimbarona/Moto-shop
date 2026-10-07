import { router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ClipboardPlus, Flag } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/pos/empty-state';
import { OrderCard } from '@/components/pos/order-card';
import { selectClass } from '@/components/pos/product-finder';
import { Button } from '@/components/ui/button';
import PosLayout from '@/layouts/pos-layout';
import { api, toApiError, urls } from '@/lib/api';
import { cn } from '@/lib/utils';
import type {
    MechanicOption,
    OrderCard as OrderCardType,
    ServiceStatus,
} from '@/types/pos';

const columns: { status: ServiceStatus; title: string; accent: string }[] = [
    { status: 'waiting', title: 'Waiting', accent: 'bg-zinc-400' },
    { status: 'in_progress', title: 'In progress', accent: 'bg-sky-500' },
    { status: 'done', title: 'Done · ready for payment', accent: 'bg-paid' },
];

export default function ServiceBoard({
    jobs,
    mechanics,
}: {
    jobs: OrderCardType[];
    mechanics: MechanicOption[];
}) {
    const { auth } = usePage().props;
    const [items, setItems] = useState(jobs);
    const [mechanic, setMechanic] = useState('');
    const [dragOver, setDragOver] = useState<ServiceStatus | null>(null);

    // Fresh jobs from the 30-second reload replace the local copy.
    const [previousJobs, setPreviousJobs] = useState(jobs);

    if (jobs !== previousJobs) {
        setPreviousJobs(jobs);
        setItems(jobs);
    }

    // Keep the board fresh on the shop TV / tablet.
    useEffect(() => {
        const timer = setInterval(
            () => router.reload({ only: ['jobs'] }),
            30_000,
        );

        return () => clearInterval(timer);
    }, []);

    const move = async (job: OrderCardType, status: ServiceStatus) => {
        if (job.service_status === status) {
            return;
        }

        if (status === 'done' && job.items_count === 0) {
            toast.error(
                'Add the parts and labor used before marking the job done.',
            );

            return;
        }

        const previous = items;
        setItems((list) =>
            list.map((j) =>
                j.id === job.id ? { ...j, service_status: status } : j,
            ),
        );

        try {
            await api.setServiceStatus(job.id, status);
            toast.success(
                `${job.plate_no ?? job.order_no} → ${columns.find((c) => c.status === status)?.title}`,
            );
        } catch (e) {
            setItems(previous);
            toast.error(toApiError(e).first());
        }
    };

    const visible = items.filter(
        (j) => !mechanic || j.mechanics.includes(mechanic),
    );

    return (
        <PosLayout title="Service Board" openOrdersCount={items.length}>
            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold">
                            Service board
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {auth.user.role === 'mechanic'
                                ? 'Your jobs. Drag a card or use the arrows to move it.'
                                : 'Drag a card or use the arrows to move it.'}
                        </p>
                    </div>
                    <div className="ml-auto flex items-center gap-2">
                        {auth.user.role !== 'mechanic' && (
                            <select
                                className={selectClass}
                                value={mechanic}
                                onChange={(e) => setMechanic(e.target.value)}
                                aria-label="Filter by mechanic"
                            >
                                <option value="">All mechanics</option>
                                {mechanics.map((m) => (
                                    <option key={m.id} value={m.name}>
                                        {m.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {auth.can.usePos && (
                            <Button
                                variant="racing"
                                size="lg"
                                onClick={() => router.visit(urls.pos())}
                            >
                                <ClipboardPlus /> New service order
                            </Button>
                        )}
                    </div>
                </div>

                {items.length === 0 ? (
                    <EmptyState
                        icon={Flag}
                        title="No open jobs — garage is clear 🏁"
                        className="flex-1"
                    >
                        New service orders from the POS appear here.
                    </EmptyState>
                ) : (
                    <div className="grid flex-1 gap-3 md:grid-cols-3">
                        {columns.map((column, index) => {
                            const cards = visible.filter(
                                (j) => j.service_status === column.status,
                            );

                            return (
                                <section
                                    key={column.status}
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                        setDragOver(column.status);
                                    }}
                                    onDragLeave={() => setDragOver(null)}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        setDragOver(null);
                                        const job = items.find(
                                            (j) =>
                                                String(j.id) ===
                                                e.dataTransfer.getData(
                                                    'text/plain',
                                                ),
                                        );

                                        if (job) {
                                            move(job, column.status);
                                        }
                                    }}
                                    className={cn(
                                        'flex min-h-64 flex-col rounded-xl border bg-muted/40 transition',
                                        dragOver === column.status &&
                                            'border-primary bg-primary/5',
                                    )}
                                >
                                    <header className="flex items-center gap-2 border-b p-3">
                                        <span
                                            className={cn(
                                                'size-3 rounded-full',
                                                column.accent,
                                            )}
                                        />
                                        <h2 className="font-display text-lg font-bold">
                                            {column.title}
                                        </h2>
                                        <span className="tabular ml-auto rounded-full bg-background px-2 text-sm font-bold">
                                            {cards.length}
                                        </span>
                                    </header>
                                    <div className="flex flex-1 flex-col gap-2 p-2">
                                        {cards.map((job) => (
                                            <OrderCard
                                                key={job.id}
                                                order={job}
                                                draggable
                                                onDragStart={(e) =>
                                                    e.dataTransfer.setData(
                                                        'text/plain',
                                                        String(job.id),
                                                    )
                                                }
                                                footer={
                                                    <div className="flex border-t">
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                index === 0
                                                            }
                                                            onClick={() =>
                                                                move(
                                                                    job,
                                                                    columns[
                                                                        index -
                                                                            1
                                                                    ].status,
                                                                )
                                                            }
                                                            className="flex h-10 flex-1 items-center justify-center gap-1 text-xs font-semibold text-muted-foreground hover:bg-muted disabled:opacity-30"
                                                        >
                                                            <ChevronLeft className="size-4" />{' '}
                                                            {index > 0 &&
                                                                columns[
                                                                    index - 1
                                                                ].title.split(
                                                                    ' ·',
                                                                )[0]}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                index ===
                                                                columns.length -
                                                                    1
                                                            }
                                                            onClick={() =>
                                                                move(
                                                                    job,
                                                                    columns[
                                                                        index +
                                                                            1
                                                                    ].status,
                                                                )
                                                            }
                                                            className="flex h-10 flex-1 items-center justify-center gap-1 border-l text-xs font-semibold text-muted-foreground hover:bg-muted disabled:opacity-30"
                                                        >
                                                            {index <
                                                                columns.length -
                                                                    1 &&
                                                                columns[
                                                                    index + 1
                                                                ].title.split(
                                                                    ' ·',
                                                                )[0]}{' '}
                                                            <ChevronRight className="size-4" />
                                                        </button>
                                                    </div>
                                                }
                                            />
                                        ))}
                                        {cards.length === 0 && (
                                            <p className="p-6 text-center text-sm text-muted-foreground">
                                                Drop a job here
                                            </p>
                                        )}
                                    </div>
                                </section>
                            );
                        })}
                    </div>
                )}
            </div>
        </PosLayout>
    );
}
