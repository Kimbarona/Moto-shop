import { Link, router } from '@inertiajs/react';
import { Banknote, Wrench } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pos/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PosLayout from '@/layouts/pos-layout';
import { urls } from '@/lib/api';
import { dateTime, peso } from '@/lib/format';

type Props = {
    from: string;
    until: string;
    cashier: null | {
        orders: number;
        total: number;
        voided: number;
        byMethod: {
            method: string;
            count: number;
            amount: number;
            references: string[];
        }[];
        recent: {
            order_id: number;
            slip_no: string | null;
            method: string | null;
            amount: number;
            paid_at: string | null;
        }[];
    };
    mechanic: null | {
        jobs: number;
        labor: number;
        commission: number;
        openJobs: number;
        lines: {
            slip_no: string | null;
            description: string;
            net: number;
            commission: number;
            paid_at: string | null;
        }[];
    };
};

export default function MySummary({ from, until, cashier, mechanic }: Props) {
    const [range, setRange] = useState({ from, until });

    return (
        <PosLayout title="My Summary">
            <div className="mx-auto w-full max-w-5xl space-y-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    <h1 className="font-display text-3xl font-extrabold">
                        My summary
                    </h1>
                    <div className="ml-auto flex items-end gap-2">
                        <Input
                            type="date"
                            value={range.from}
                            onChange={(e) =>
                                setRange({ ...range, from: e.target.value })
                            }
                            aria-label="From"
                        />
                        <Input
                            type="date"
                            value={range.until}
                            onChange={(e) =>
                                setRange({ ...range, until: e.target.value })
                            }
                            aria-label="Until"
                        />
                        <Button
                            onClick={() =>
                                router.get(urls.mySummary, range, {
                                    preserveState: true,
                                })
                            }
                        >
                            Show
                        </Button>
                    </div>
                </div>

                {cashier && (
                    <section className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Stat
                                label="Sales collected"
                                value={peso(cashier.total)}
                                highlight
                            />
                            <Stat
                                label="Orders paid"
                                value={String(cashier.orders)}
                            />
                            <Stat
                                label="Voided"
                                value={String(cashier.voided)}
                            />
                        </div>
                        <div className="rounded-xl border bg-card">
                            <h2 className="flex items-center gap-2 border-b p-3 font-display text-lg font-bold">
                                <Banknote className="size-5" /> By payment
                                method
                            </h2>
                            {cashier.byMethod.length === 0 ? (
                                <EmptyState
                                    icon={Banknote}
                                    title="No sales in this period"
                                    className="py-6"
                                />
                            ) : (
                                <table className="w-full text-sm">
                                    <tbody className="divide-y">
                                        {cashier.byMethod.map((m) => (
                                            <tr key={m.method}>
                                                <td className="p-3 font-semibold">
                                                    {m.method}
                                                </td>
                                                <td className="p-3 text-muted-foreground">
                                                    {m.count} payment(s)
                                                    {m.references.length > 0 &&
                                                        ` · refs ${m.references.join(', ')}`}
                                                </td>
                                                <td className="tabular p-3 text-right font-display text-xl font-bold">
                                                    {peso(m.amount)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                        {cashier.recent.length > 0 && (
                            <div className="rounded-xl border bg-card">
                                <h2 className="border-b p-3 font-display text-lg font-bold">
                                    Latest payments
                                </h2>
                                <ul className="divide-y text-sm">
                                    {cashier.recent.map((p, i) => (
                                        <li
                                            key={i}
                                            className="flex items-center gap-3 p-3"
                                        >
                                            <Link
                                                href={urls.order(p.order_id)}
                                                className="font-semibold text-primary hover:underline"
                                            >
                                                {p.slip_no}
                                            </Link>
                                            <span className="text-muted-foreground">
                                                {p.method} ·{' '}
                                                {dateTime(p.paid_at)}
                                            </span>
                                            <span className="tabular ml-auto">
                                                {peso(p.amount)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}

                {mechanic && (
                    <section className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-4">
                            <Stat
                                label="Commission"
                                value={peso(mechanic.commission)}
                                highlight
                            />
                            <Stat
                                label="Jobs paid"
                                value={String(mechanic.jobs)}
                            />
                            <Stat
                                label="Labor amount"
                                value={peso(mechanic.labor)}
                            />
                            <Stat
                                label="Open jobs"
                                value={String(mechanic.openJobs)}
                            />
                        </div>
                        <div className="rounded-xl border bg-card">
                            <h2 className="flex items-center gap-2 border-b p-3 font-display text-lg font-bold">
                                <Wrench className="size-5" /> My labor lines
                            </h2>
                            {mechanic.lines.length === 0 ? (
                                <EmptyState
                                    icon={Wrench}
                                    title="No paid jobs in this period"
                                    className="py-6"
                                />
                            ) : (
                                <ul className="divide-y text-sm">
                                    {mechanic.lines.map((l, i) => (
                                        <li
                                            key={i}
                                            className="flex items-center gap-3 p-3"
                                        >
                                            <span className="font-semibold">
                                                {l.slip_no}
                                            </span>
                                            <span>{l.description}</span>
                                            <span className="text-muted-foreground">
                                                {dateTime(l.paid_at)}
                                            </span>
                                            <span className="tabular ml-auto">
                                                {peso(l.net)}
                                            </span>
                                            <span className="tabular w-24 text-right font-semibold text-paid">
                                                {peso(l.commission)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>
                )}

                {!cashier && !mechanic && (
                    <EmptyState
                        icon={Banknote}
                        title="Nothing to summarize for your role"
                    />
                )}
            </div>
        </PosLayout>
    );
}

function Stat({
    label,
    value,
    highlight,
}: {
    label: string;
    value: string;
    highlight?: boolean;
}) {
    return (
        <div
            className={
                highlight
                    ? 'racing-stripe rounded-xl bg-asphalt p-4 text-white'
                    : 'rounded-xl border bg-card p-4'
            }
        >
            <p className="font-display text-sm tracking-widest opacity-70">
                {label}
            </p>
            <p
                className={
                    highlight
                        ? 'tabular font-display text-3xl font-extrabold text-racing'
                        : 'tabular font-display text-3xl font-extrabold'
                }
            >
                {value}
            </p>
        </div>
    );
}
