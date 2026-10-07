import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Ban, History, Pencil, Printer, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { StatusBadge } from '@/components/pos/status-badge';
import { VoidDialog } from '@/components/pos/void-dialog';
import { Button } from '@/components/ui/button';
import PosLayout from '@/layouts/pos-layout';
import { openPrint, urls } from '@/lib/api';
import { dateTime, peso, qty } from '@/lib/format';
import type { Order, ReturnSummary } from '@/types/pos';

type TimelineEntry = {
    at: string | null;
    user: string | null;
    event: string;
    text: string;
};

export default function OrderShow({
    order,
    timeline,
    returns = [],
}: {
    order: Order;
    timeline: TimelineEntry[];
    returns?: ReturnSummary[];
}) {
    const { auth } = usePage().props;
    const [voidOpen, setVoidOpen] = useState(false);
    const isToday =
        order.paid_at &&
        new Date(order.paid_at).toDateString() === new Date().toDateString();
    const canVoid =
        order.can.void &&
        (order.status === 'pending' ||
            (order.status === 'paid' &&
                isToday &&
                order.refund_status === 'none'));

    return (
        <PosLayout title={order.slip_no ?? order.order_no}>
            <div className="mx-auto w-full max-w-6xl space-y-4 p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={() => window.history.back()}
                        className="text-muted-foreground hover:text-foreground"
                        aria-label="Back"
                    >
                        <ArrowLeft className="size-6" />
                    </button>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="font-display text-3xl font-extrabold">
                                {order.slip_no ?? order.order_no}
                            </h1>
                            <StatusBadge status={order.status} />
                            {order.service_status &&
                                order.status === 'pending' && (
                                    <StatusBadge
                                        status={order.service_status}
                                    />
                                )}
                            {order.refund_status !== 'none' && (
                                <StatusBadge status={order.refund_status} />
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {order.order_no} ·{' '}
                            {order.type === 'service'
                                ? 'Service order'
                                : 'Walk-in'}{' '}
                            · opened {dateTime(order.created_at)}
                            {order.created_by && ` by ${order.created_by}`}
                        </p>
                    </div>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {order.status === 'pending' && order.can.editItems && (
                            <Button asChild variant="racing" size="lg">
                                <Link
                                    href={
                                        auth.can.usePos
                                            ? urls.pos(order.id)
                                            : urls.job(order.id)
                                    }
                                >
                                    <Pencil /> Resume
                                </Link>
                            </Button>
                        )}
                        {order.status !== 'pending' && (
                            <Button
                                variant="outline"
                                size="lg"
                                onClick={() =>
                                    openPrint(urls.slip(order.id, true))
                                }
                            >
                                <Printer /> Reprint slip
                            </Button>
                        )}
                        {order.type === 'service' && (
                            <Button
                                variant="outline"
                                size="lg"
                                onClick={() =>
                                    openPrint(urls.jobTicket(order.id, true))
                                }
                            >
                                <Printer /> Job ticket
                            </Button>
                        )}
                        {order.can.return && (
                            <Button variant="outline" size="lg" asChild>
                                <Link href={urls.returnFor(order.id)}>
                                    <Undo2 /> Return / refund
                                </Link>
                            </Button>
                        )}
                        {canVoid && (
                            <Button
                                variant="outline"
                                size="lg"
                                className="text-destructive"
                                onClick={() => setVoidOpen(true)}
                            >
                                <Ban /> Void
                            </Button>
                        )}
                    </div>
                </div>

                {order.status === 'voided' && (
                    <div className="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm">
                        <span className="font-bold">Voided</span>{' '}
                        {dateTime(order.voided_at)} by {order.voided_by}:{' '}
                        {order.void_reason}
                    </div>
                )}

                <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
                    <div className="space-y-4">
                        <section className="rounded-xl border bg-card">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="p-3">Item</th>
                                        <th className="p-3 text-right">Qty</th>
                                        <th className="p-3 text-right">
                                            Price
                                        </th>
                                        <th className="p-3 text-right">
                                            Discount
                                        </th>
                                        <th className="p-3 text-right">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {order.items.map((item) => (
                                        <tr key={item.id}>
                                            <td className="p-3">
                                                <p className="font-semibold">
                                                    {item.description}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.line_type === 'labor'
                                                        ? `Labor${item.mechanic ? ` · ${item.mechanic}` : ''}`
                                                        : item.sku}
                                                    {item.package &&
                                                        ` · ${item.package}`}
                                                    {item.qty_returned > 0 && (
                                                        <span className="font-semibold text-refunded">
                                                            {' '}
                                                            ·{' '}
                                                            {qty(
                                                                item.qty_returned,
                                                            )}{' '}
                                                            returned
                                                        </span>
                                                    )}
                                                    {item.commission !==
                                                        undefined &&
                                                        item.commission > 0 &&
                                                        ` · commission ${peso(item.commission)}`}
                                                    {item.unit_cost !==
                                                        undefined &&
                                                        item.unit_cost !==
                                                            null &&
                                                        ` · cost ${peso(item.unit_cost)}`}
                                                </p>
                                            </td>
                                            <td className="tabular p-3 text-right">
                                                {qty(item.qty)} {item.unit}
                                            </td>
                                            <td className="tabular p-3 text-right">
                                                {peso(item.unit_price)}
                                            </td>
                                            <td className="tabular p-3 text-right">
                                                {item.discount +
                                                    item.discount_share >
                                                0
                                                    ? `−${peso(item.discount + item.discount_share)}`
                                                    : '—'}
                                            </td>
                                            <td className="tabular p-3 text-right font-semibold">
                                                {peso(item.net)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </section>

                        <section className="rounded-xl border bg-card p-4">
                            <h2 className="mb-3 flex items-center gap-2 font-display text-xl font-bold">
                                <History className="size-5" /> Timeline
                            </h2>
                            <ol className="space-y-3 border-l-2 border-primary/40 pl-4">
                                {timeline.map((entry, i) => (
                                    <li key={i} className="relative">
                                        <span className="absolute top-1.5 -left-[21px] size-2.5 rounded-full bg-primary" />
                                        <p className="text-sm">{entry.text}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {dateTime(entry.at)}
                                            {entry.user && ` · ${entry.user}`}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    </div>

                    <aside className="space-y-4">
                        <section className="space-y-1 rounded-xl border bg-card p-4 text-sm">
                            <h2 className="font-display text-lg font-bold">
                                Customer
                            </h2>
                            {order.customer ? (
                                <Link
                                    href={urls.customer(order.customer.id)}
                                    className="font-semibold text-primary hover:underline"
                                >
                                    {order.customer.name}
                                </Link>
                            ) : (
                                <p className="text-muted-foreground">
                                    Walk-in, no customer
                                </p>
                            )}
                            {order.motorcycle && (
                                <p>
                                    <span className="font-display text-lg font-bold">
                                        {order.motorcycle.plate_no ??
                                            'No plate'}
                                    </span>{' '}
                                    · {order.motorcycle.model}
                                </p>
                            )}
                            {order.odometer_in !== null && (
                                <p className="text-muted-foreground">
                                    Odometer {qty(order.odometer_in)} km
                                </p>
                            )}
                            {order.mechanics.length > 0 && (
                                <p className="text-muted-foreground">
                                    Mechanic:{' '}
                                    {order.mechanics
                                        .map((m) => m.name)
                                        .join(', ')}
                                </p>
                            )}
                            {order.complaint && (
                                <p className="italic">“{order.complaint}”</p>
                            )}
                        </section>

                        <section className="space-y-1 rounded-xl border bg-card p-4 text-sm">
                            <Row
                                label="Subtotal"
                                value={peso(order.subtotal)}
                            />
                            <Row
                                label="Discount"
                                value={
                                    order.discount_total > 0
                                        ? `−${peso(order.discount_total)}`
                                        : peso(0)
                                }
                            />
                            {order.vat_amount > 0 && (
                                <Row
                                    label="VAT"
                                    value={peso(order.vat_amount)}
                                />
                            )}
                            {order.return_credit > 0 && (
                                <Row
                                    label="Return credit"
                                    value={`−${peso(order.return_credit)}`}
                                />
                            )}
                            <div className="flex items-end justify-between border-t pt-2">
                                <span className="font-display text-lg font-bold">
                                    Total
                                </span>
                                <span className="tabular font-display text-3xl font-extrabold text-primary">
                                    {peso(order.amount_due)}
                                </span>
                            </div>
                            {order.profit !== undefined && (
                                <Row
                                    label="Gross profit"
                                    value={peso(order.profit)}
                                />
                            )}
                        </section>

                        {returns.length > 0 && (
                            <section className="space-y-2 rounded-xl border bg-card p-4 text-sm">
                                <h2 className="font-display text-lg font-bold">
                                    Returns
                                </h2>
                                {returns.map((r) => (
                                    <div key={r.id} className="space-y-0.5">
                                        <Row
                                            label={`${r.return_no} · ${r.resolution === 'refund' ? 'Refund' : 'Exchange'}`}
                                            value={`−${peso(r.return_total)}`}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            {dateTime(r.date)} · {r.reason}
                                            {r.exchange_order &&
                                                ` · credit used on ${r.exchange_order.slip_no}`}
                                            {r.credit_available &&
                                                ' · credit not used yet'}
                                        </p>
                                        <div className="flex gap-2 pt-1">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    openPrint(
                                                        urls.returnSlip(
                                                            r.id,
                                                            true,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Printer /> Return slip
                                            </Button>
                                            {r.credit_available &&
                                                auth.can.takePayment && (
                                                    <Button
                                                        size="sm"
                                                        variant="racing"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={urls.exchange(
                                                                r.id,
                                                            )}
                                                        >
                                                            Use credit
                                                        </Link>
                                                    </Button>
                                                )}
                                        </div>
                                    </div>
                                ))}
                            </section>
                        )}

                        {order.payments.length > 0 && (
                            <section className="space-y-2 rounded-xl border bg-card p-4 text-sm">
                                <h2 className="font-display text-lg font-bold">
                                    Payment
                                </h2>
                                {order.payments.map((p) => (
                                    <div
                                        key={p.id}
                                        className={
                                            p.voided
                                                ? 'line-through opacity-60'
                                                : ''
                                        }
                                    >
                                        <Row
                                            label={p.method ?? ''}
                                            value={peso(p.amount)}
                                        />
                                        {p.amount_tendered !== null &&
                                            p.change_due > 0 && (
                                                <Row
                                                    label="Tendered / change"
                                                    value={`${peso(p.amount_tendered)} / ${peso(p.change_due)}`}
                                                />
                                            )}
                                        {p.reference_no && (
                                            <Row
                                                label="Ref no."
                                                value={p.reference_no}
                                            />
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {dateTime(p.paid_at)} ·{' '}
                                            {order.cashier}
                                        </p>
                                    </div>
                                ))}
                            </section>
                        )}
                    </aside>
                </div>
            </div>

            <VoidDialog
                order={order}
                open={voidOpen}
                onOpenChange={setVoidOpen}
                onVoided={() => router.reload()}
            />
        </PosLayout>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-2">
            <span className="text-muted-foreground">{label}</span>
            <span className="tabular">{value}</span>
        </div>
    );
}
