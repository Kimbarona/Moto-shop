import { Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Bike,
    Minus,
    PackageCheck,
    PackageX,
    Plus,
    Printer,
    Receipt,
    RefreshCcw,
    Search,
    Undo2,
    Wallet,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { EmptyState } from '@/components/pos/empty-state';
import { StatusBadge } from '@/components/pos/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { usePin } from '@/hooks/use-pin';
import PosLayout from '@/layouts/pos-layout';
import { api, openPrint, toApiError, urls } from '@/lib/api';
import { date, dateTime, peso, qty } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    Disposition,
    Option,
    PaymentMethod,
    Resolution,
    ReturnableLine,
    ReturnableOrder,
    ReturnableOrderHit,
    ReturnSummary,
} from '@/types/pos';

type Props = {
    order: ReturnableOrder | null;
    reasons: Option[];
    dispositions: Option[];
    paymentMethods: PaymentMethod[];
    openOrdersCount: number;
    recent: ReturnSummary[];
};

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/**
 * What the customer paid for `amount` of this line. Mirrors ReturnService: a partial
 * return is pro rata, and returning everything left takes exactly what is left.
 */
function lineAmount(line: ReturnableLine, amount: number): number {
    if (amount <= 0) {
        return 0;
    }

    if (Math.abs(amount - line.returnable) < 0.005) {
        return line.remaining_amount;
    }

    return round2((line.net * amount) / line.qty);
}

export default function ReturnsNew(props: Props) {
    return (
        <PosLayout title="Returns" openOrdersCount={props.openOrdersCount}>
            <div className="mx-auto w-full max-w-7xl space-y-4 p-4">
                {props.order ? (
                    <ReturnForm
                        key={props.order.id}
                        {...props}
                        order={props.order}
                    />
                ) : (
                    <>
                        <div>
                            <h1 className="flex items-center gap-2 font-display text-3xl font-extrabold">
                                <Undo2 className="size-7 text-refunded" />{' '}
                                Return / refund
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Find the paid order by slip no., customer or
                                plate no. Same-day mistakes can still be voided
                                from the order instead.
                            </p>
                        </div>
                        <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
                            <OrderSearch />
                            <RecentReturns recent={props.recent} />
                        </div>
                    </>
                )}
            </div>
        </PosLayout>
    );
}

/* ------------------------------------------------------------ find the order */

function useReturnableOrders(q: string) {
    const [state, setState] = useState<{
        key: string | null;
        hits: ReturnableOrderHit[];
        error: string | null;
    }>({ key: null, hits: [], error: null });

    useEffect(() => {
        let cancelled = false;

        api.returnableOrders(q)
            .then(
                (hits) => !cancelled && setState({ key: q, hits, error: null }),
            )
            .catch(
                (error) =>
                    !cancelled &&
                    setState({
                        key: q,
                        hits: [],
                        error: toApiError(error).first(),
                    }),
            );

        return () => {
            cancelled = true;
        };
    }, [q]);

    return { ...state, loading: state.key !== q };
}

function OrderSearch() {
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    const { hits, loading, error } = useReturnableOrders(debounced);

    useEffect(() => {
        const t = setTimeout(() => setDebounced(q.trim()), 250);

        return () => clearTimeout(t);
    }, [q]);

    const pick = (hit: ReturnableOrderHit) =>
        router.visit(urls.returnFor(hit.id));

    return (
        <section className="space-y-3 rounded-xl border bg-card p-4">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    autoFocus
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => {
                        if (
                            e.key === 'Enter' &&
                            !loading &&
                            hits.length === 1
                        ) {
                            pick(hits[0]);
                        }
                    }}
                    placeholder="Slip no., customer name / mobile, or plate no."
                    className="h-12 pl-10 text-lg"
                    aria-label="Search paid orders"
                />
            </div>

            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                {debounced ? 'Matching paid orders' : 'Latest paid orders'}
            </p>

            {error && <p className="text-sm text-destructive">{error}</p>}

            {loading ? (
                <div className="space-y-2" data-testid="returns-loading">
                    {[0, 1, 2, 3].map((i) => (
                        <Skeleton key={i} className="h-16 w-full" />
                    ))}
                </div>
            ) : hits.length === 0 ? (
                <EmptyState icon={Receipt} title="No paid orders found">
                    Fully refunded and voided orders are not listed.
                </EmptyState>
            ) : (
                <ul
                    className="divide-y rounded-lg border"
                    data-testid="return-hits"
                >
                    {hits.map((hit) => (
                        <li key={hit.id}>
                            <button
                                type="button"
                                onClick={() => pick(hit)}
                                className="flex w-full items-center gap-3 p-3 text-left transition hover:bg-muted/60"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-display text-lg font-extrabold">
                                            {hit.slip_no}
                                        </span>
                                        {hit.refund_status !== 'none' && (
                                            <StatusBadge
                                                status={hit.refund_status}
                                            />
                                        )}
                                        <span className="text-xs text-muted-foreground">
                                            {dateTime(hit.paid_at)}
                                        </span>
                                    </div>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {hit.customer ?? 'Walk-in'}
                                        {hit.plate_no && (
                                            <>
                                                {' · '}
                                                <Bike className="inline size-3.5" />{' '}
                                                <span className="font-semibold text-foreground">
                                                    {hit.plate_no}
                                                </span>
                                            </>
                                        )}
                                        {' · '}
                                        {hit.items_count} item
                                        {hit.items_count === 1 ? '' : 's'}
                                    </p>
                                </div>
                                <span className="tabular font-display text-xl font-bold">
                                    {peso(hit.total)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function RecentReturns({ recent }: { recent: ReturnSummary[] }) {
    return (
        <section className="space-y-3 rounded-xl border bg-card p-4">
            <h2 className="font-display text-xl font-bold">Recent returns</h2>
            {recent.length === 0 ? (
                <p className="text-sm text-muted-foreground">No returns yet.</p>
            ) : (
                <ul className="divide-y text-sm">
                    {recent.map((r) => (
                        <li key={r.id} className="space-y-1 py-2">
                            <div className="flex items-center gap-2">
                                <span className="font-semibold">
                                    {r.return_no}
                                </span>
                                <span
                                    className={cn(
                                        'rounded px-1.5 text-xs font-bold',
                                        r.resolution === 'refund'
                                            ? 'bg-refunded/15 text-refunded'
                                            : 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
                                    )}
                                >
                                    {r.resolution === 'refund'
                                        ? 'Refund'
                                        : 'Exchange'}
                                </span>
                                <span className="tabular ml-auto font-semibold">
                                    {peso(r.return_total)}
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {dateTime(r.date)} · slip{' '}
                                <Link
                                    href={urls.order(r.order_id)}
                                    className="underline"
                                >
                                    {r.slip_no}
                                </Link>
                                {r.customer && ` · ${r.customer}`} · {r.reason}
                                {r.exchange_order &&
                                    ` · used on ${r.exchange_order.slip_no}`}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        openPrint(urls.returnSlip(r.id, true))
                                    }
                                >
                                    <Printer /> Slip
                                </Button>
                                {r.credit_available && (
                                    <Button size="sm" variant="racing" asChild>
                                        <Link href={urls.exchange(r.id)}>
                                            <RefreshCcw /> Use{' '}
                                            {peso(r.return_total)} credit
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/* ----------------------------------------------------------- pick the lines */

type LineState = { qty: string; disposition: Disposition | null };

function ReturnForm({
    order,
    reasons,
    paymentMethods,
}: Props & { order: ReturnableOrder }) {
    const { withPin } = usePin();
    const [lines, setLines] = useState<Record<number, LineState>>({});
    const [reason, setReason] = useState<string>('');
    const [remarks, setRemarks] = useState('');
    const [resolution, setResolution] = useState<Resolution>('refund');
    const [methodId, setMethodId] = useState<number | null>(
        paymentMethods.find((m) => m.type === 'cash')?.id ??
            paymentMethods[0]?.id ??
            null,
    );
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);

    // Parts follow the reason (defective → defective bucket) unless set per line.
    const defaultDisposition: Disposition =
        reason === 'defective' ? 'defective' : 'back_to_stock';

    const qtyOf = (line: ReturnableLine) => {
        const value = Number(lines[line.id]?.qty ?? 0);

        return Number.isFinite(value) ? Math.max(0, value) : 0;
    };
    const dispositionOf = (line: ReturnableLine): Disposition | null =>
        line.line_type === 'part'
            ? (lines[line.id]?.disposition ?? defaultDisposition)
            : null;

    const setLine = (line: ReturnableLine, changes: Partial<LineState>) =>
        setLines((all) => ({
            ...all,
            [line.id]: {
                qty: all[line.id]?.qty ?? '0',
                disposition: all[line.id]?.disposition ?? null,
                ...changes,
            },
        }));

    const step = (line: ReturnableLine, delta: number) => {
        const next = Math.min(
            line.returnable,
            Math.max(0, round2(qtyOf(line) + delta)),
        );
        setLine(line, { qty: String(next) });
    };

    const picked = order.items.filter((line) => qtyOf(line) > 0);
    const over = picked.filter(
        (line) => qtyOf(line) > line.returnable + 0.0001,
    );
    const total = round2(
        picked.reduce((sum, line) => sum + lineAmount(line, qtyOf(line)), 0),
    );
    const method = paymentMethods.find((m) => m.id === methodId) ?? null;
    const missingRef =
        resolution === 'refund' &&
        !!method?.requires_reference &&
        reference.trim() === '';
    const blocked =
        busy ||
        picked.length === 0 ||
        over.length > 0 ||
        reason === '' ||
        (resolution === 'refund' && (!method || missingRef));

    const submit = async (event?: FormEvent) => {
        event?.preventDefault();

        if (blocked) {
            return;
        }

        setBusy(true);

        try {
            const result = await withPin((pin) =>
                api.createReturn({
                    order_id: order.id,
                    reason,
                    remarks: remarks.trim() || null,
                    resolution,
                    refund_method_id: resolution === 'refund' ? methodId : null,
                    refund_reference:
                        resolution === 'refund'
                            ? reference.trim() || null
                            : null,
                    items: picked.map((line) => ({
                        order_item_id: line.id,
                        qty: qtyOf(line),
                        disposition: dispositionOf(line),
                    })),
                    pin,
                }),
            );

            if (!result) {
                return;
            }

            openPrint(urls.returnSlip(result.id));

            if (result.resolution === 'exchange') {
                toast.success(`${result.return_no} recorded`, {
                    description: `${peso(result.return_total)} credit. Ring up the replacement items.`,
                });
                router.visit(urls.exchange(result.id));
            } else {
                toast.success(
                    `Refunded ${peso(result.refund_amount)} · ${result.return_no}`,
                );
                router.visit(urls.returns);
            }
        } catch (e) {
            toast.error(toApiError(e).first());
        } finally {
            setBusy(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <Link
                    href={urls.returns}
                    className="text-muted-foreground hover:text-foreground"
                    aria-label="Back to search"
                >
                    <ArrowLeft className="size-6" />
                </Link>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="font-display text-3xl font-extrabold">
                            Return on {order.slip_no}
                        </h1>
                        {order.refund_status !== 'none' && (
                            <StatusBadge status={order.refund_status} />
                        )}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        Paid {dateTime(order.paid_at)}
                        {order.cashier && ` by ${order.cashier}`} ·{' '}
                        {order.customer?.name ?? 'Walk-in'}
                        {order.motorcycle &&
                            ` · ${order.motorcycle.plate_no ?? 'No plate'} ${order.motorcycle.model ?? ''}`}{' '}
                        · total {peso(order.total)}
                    </p>
                    {order.returns.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            Earlier returns:{' '}
                            {order.returns
                                .map(
                                    (r) =>
                                        `${r.return_no} (${peso(r.return_total)}, ${date(r.date)})`,
                                )
                                .join(', ')}
                        </p>
                    )}
                </div>
                <Button variant="outline" asChild>
                    <Link href={urls.order(order.id)}>View order</Link>
                </Button>
            </div>

            <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
                <section className="overflow-hidden rounded-xl border bg-card">
                    <div className="flex items-center justify-between border-b bg-muted/50 px-3 py-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        <span>Items on the slip</span>
                        <button
                            type="button"
                            className="font-semibold text-primary normal-case hover:underline"
                            onClick={() =>
                                setLines(
                                    Object.fromEntries(
                                        order.items
                                            .filter((l) => l.returnable > 0)
                                            .map((l) => [
                                                l.id,
                                                {
                                                    qty: String(l.returnable),
                                                    disposition:
                                                        lines[l.id]
                                                            ?.disposition ??
                                                        null,
                                                },
                                            ]),
                                    ),
                                )
                            }
                        >
                            Return everything left
                        </button>
                    </div>
                    <ul className="divide-y">
                        {order.items.map((line) => {
                            const value = qtyOf(line);
                            const done = line.returnable <= 0;
                            const disposition = dispositionOf(line);
                            const tooMany = value > line.returnable + 0.0001;

                            return (
                                <li
                                    key={line.id}
                                    data-testid={`return-line-${line.id}`}
                                    className={cn(
                                        'space-y-2 p-3',
                                        done && 'opacity-50',
                                        value > 0 && 'bg-refunded/5',
                                    )}
                                >
                                    <div className="flex items-start gap-3">
                                        <div className="min-w-0 flex-1">
                                            <p className="font-semibold">
                                                {line.description}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {line.line_type === 'labor'
                                                    ? 'Labor'
                                                    : line.sku}{' '}
                                                · sold {qty(line.qty)}
                                                {line.unit
                                                    ? ` ${line.unit}`
                                                    : ''}{' '}
                                                · paid {peso(line.net)}
                                                {line.qty_returned > 0 && (
                                                    <span className="font-semibold text-refunded">
                                                        {' '}
                                                        ·{' '}
                                                        {qty(
                                                            line.qty_returned,
                                                        )}{' '}
                                                        already returned
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        <span className="tabular w-24 text-right font-display text-lg font-bold">
                                            {value > 0
                                                ? `−${peso(lineAmount(line, Math.min(value, line.returnable)))}`
                                                : ''}
                                        </span>
                                    </div>

                                    {done ? (
                                        <p className="text-xs font-semibold text-refunded">
                                            Returned in full
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap items-center gap-2">
                                            <div className="flex items-center">
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="outline"
                                                    onClick={() =>
                                                        step(line, -1)
                                                    }
                                                    aria-label={`Less ${line.description}`}
                                                >
                                                    <Minus />
                                                </Button>
                                                <Input
                                                    inputMode="decimal"
                                                    value={
                                                        lines[line.id]?.qty ??
                                                        '0'
                                                    }
                                                    onFocus={(e) =>
                                                        e.target.select()
                                                    }
                                                    onChange={(e) =>
                                                        setLine(line, {
                                                            qty: e.target.value.replace(
                                                                line.allows_decimal
                                                                    ? /[^\d.]/g
                                                                    : /\D/g,
                                                                '',
                                                            ),
                                                        })
                                                    }
                                                    className={cn(
                                                        'tabular mx-1 h-9 w-16 text-center text-lg font-bold',
                                                        tooMany &&
                                                            'border-destructive text-destructive',
                                                    )}
                                                    aria-label={`Qty to return of ${line.description}`}
                                                />
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="outline"
                                                    onClick={() =>
                                                        step(line, 1)
                                                    }
                                                    aria-label={`More ${line.description}`}
                                                >
                                                    <Plus />
                                                </Button>
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                of {qty(line.returnable)}{' '}
                                                returnable
                                            </span>

                                            {line.line_type === 'part' &&
                                                value > 0 && (
                                                    <div className="ml-auto grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                                                        <DispositionButton
                                                            active={
                                                                disposition ===
                                                                'back_to_stock'
                                                            }
                                                            onClick={() =>
                                                                setLine(line, {
                                                                    disposition:
                                                                        'back_to_stock',
                                                                })
                                                            }
                                                            icon={PackageCheck}
                                                            label="Back to stock"
                                                            tone="paid"
                                                        />
                                                        <DispositionButton
                                                            active={
                                                                disposition ===
                                                                'defective'
                                                            }
                                                            onClick={() =>
                                                                setLine(line, {
                                                                    disposition:
                                                                        'defective',
                                                                })
                                                            }
                                                            icon={PackageX}
                                                            label="Defective"
                                                            tone="destructive"
                                                        />
                                                    </div>
                                                )}
                                            {line.line_type === 'labor' &&
                                                value > 0 && (
                                                    <span className="ml-auto text-xs text-muted-foreground">
                                                        Labor refund · mechanic
                                                        commission is reversed
                                                    </span>
                                                )}
                                        </div>
                                    )}
                                    {tooMany && (
                                        <p className="text-xs text-destructive">
                                            Only {qty(line.returnable)} can
                                            still be returned.
                                        </p>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                </section>

                <aside className="space-y-4">
                    <section className="space-y-3 rounded-xl border bg-card p-4">
                        <Label>Reason</Label>
                        <div className="grid grid-cols-2 gap-2">
                            {reasons.map((r) => (
                                <button
                                    key={r.value}
                                    type="button"
                                    onClick={() => setReason(r.value)}
                                    className={cn(
                                        'h-11 rounded-lg border-2 text-sm font-semibold transition',
                                        reason === r.value
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border hover:border-primary/50',
                                    )}
                                >
                                    {r.label}
                                </button>
                            ))}
                        </div>
                        <Input
                            value={remarks}
                            onChange={(e) => setRemarks(e.target.value)}
                            placeholder="Remarks (optional)"
                            maxLength={1000}
                        />
                    </section>

                    <section className="space-y-3 rounded-xl border bg-card p-4">
                        <Label>Give the customer</Label>
                        <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                            {(['refund', 'exchange'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setResolution(value)}
                                    className={cn(
                                        'flex h-11 items-center justify-center gap-2 rounded-md font-display text-base font-bold tracking-wide transition',
                                        resolution === value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:bg-background',
                                    )}
                                >
                                    {value === 'refund' ? (
                                        <Wallet className="size-4" />
                                    ) : (
                                        <RefreshCcw className="size-4" />
                                    )}
                                    {value === 'refund' ? 'Refund' : 'Exchange'}
                                </button>
                            ))}
                        </div>

                        {resolution === 'refund' ? (
                            <>
                                <div className="grid grid-cols-2 gap-2">
                                    {paymentMethods.map((m) => (
                                        <button
                                            key={m.id}
                                            type="button"
                                            onClick={() => setMethodId(m.id)}
                                            className={cn(
                                                'h-10 rounded-lg border-2 text-sm font-semibold transition',
                                                m.id === methodId
                                                    ? 'border-primary bg-primary/10 text-primary'
                                                    : 'border-border hover:border-primary/50',
                                            )}
                                        >
                                            {m.name}
                                        </button>
                                    ))}
                                </div>
                                {method?.requires_reference && (
                                    <Input
                                        value={reference}
                                        onChange={(e) =>
                                            setReference(e.target.value)
                                        }
                                        placeholder={`${method.name} reference no.`}
                                        aria-label="Refund reference no."
                                    />
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                The return becomes credit on a new sale. Next
                                you ring up the replacement items; the customer
                                pays the difference, or gets back what is left
                                of the credit.
                            </p>
                        )}
                    </section>

                    <section className="space-y-3 rounded-xl border bg-card p-4">
                        <div className="flex items-end justify-between">
                            <span className="font-display text-lg font-bold">
                                {resolution === 'refund' ? 'Refund' : 'Credit'}
                            </span>
                            <span
                                className="tabular font-display text-4xl font-extrabold text-refunded"
                                data-testid="return-total"
                            >
                                {peso(total)}
                            </span>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Amounts are what the customer paid, after discounts.
                            Cashiers need a manager PIN.
                        </p>
                        <Button
                            type="submit"
                            variant="racing"
                            size="xl"
                            className="w-full text-xl"
                            disabled={blocked}
                        >
                            {busy && <Spinner />}
                            {picked.length === 0
                                ? 'Pick items to return'
                                : reason === ''
                                  ? 'Pick a reason'
                                  : resolution === 'refund'
                                    ? `Refund ${peso(total)}`
                                    : `Exchange · ${peso(total)} credit`}
                        </Button>
                    </section>
                </aside>
            </div>
        </form>
    );
}

function DispositionButton({
    active,
    onClick,
    icon: Icon,
    label,
    tone,
}: {
    active: boolean;
    onClick: () => void;
    icon: typeof PackageCheck;
    label: string;
    tone: 'paid' | 'destructive';
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'flex h-8 items-center gap-1 rounded-md px-2 text-xs font-bold transition',
                active
                    ? tone === 'paid'
                        ? 'bg-paid text-white'
                        : 'bg-destructive text-white'
                    : 'text-muted-foreground hover:bg-background',
            )}
        >
            <Icon className="size-4" /> {label}
        </button>
    );
}
