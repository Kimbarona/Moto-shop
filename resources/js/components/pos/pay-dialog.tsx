import { Banknote, CreditCard, Landmark, Smartphone } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { PaymentPayload } from '@/lib/api';
import { peso } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PaymentMethod } from '@/types/pos';

const methodIcon = {
    cash: Banknote,
    ewallet: Smartphone,
    bank: Landmark,
} as const;

/** Round-up quick amounts for cash: exact, next ₱50 / ₱100 / ₱500 / ₱1,000. */
function quickAmounts(due: number): number[] {
    const set = new Set<number>([due]);

    for (const step of [50, 100, 500, 1000]) {
        set.add(Math.ceil(due / step) * step);
    }

    return [...set]
        .filter((n) => n >= due)
        .sort((a, b) => a - b)
        .slice(0, 5);
}

/** Mounted only while open, so every payment starts with a fresh form. */
export function PayDialog(props: PayDialogProps) {
    return props.open ? <PayForm {...props} /> : null;
}

type PayDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    amountDue: number;
    methods: PaymentMethod[];
    defaultMethodId: number | null;
    title?: string;
    onPay: (payment: PaymentPayload) => Promise<boolean>;
};

function PayForm({
    open,
    onOpenChange,
    amountDue,
    methods,
    defaultMethodId,
    title = 'Payment',
    onPay,
}: PayDialogProps) {
    const [methodId, setMethodId] = useState<number | null>(
        defaultMethodId ?? methods[0]?.id ?? null,
    );
    const [tendered, setTendered] = useState('');
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);

    const method = methods.find((m) => m.id === methodId) ?? null;
    const isCash = method?.type === 'cash';
    const tenderedAmount = tendered === '' ? amountDue : Number(tendered);
    const change = Math.max(
        0,
        Math.round((tenderedAmount - amountDue) * 100) / 100,
    );
    const short = isCash && tenderedAmount < amountDue;
    const missingRef = !!method?.requires_reference && reference.trim() === '';
    const quick = useMemo(() => quickAmounts(amountDue), [amountDue]);

    const submit = async (event?: FormEvent) => {
        event?.preventDefault();

        if (busy || !method || short || missingRef) {
            return;
        }

        setBusy(true);

        try {
            const ok = await onPay({
                payment_method_id: method.id,
                amount_tendered: isCash ? tenderedAmount : null,
                reference_no: reference.trim() || null,
            });

            if (ok) {
                onOpenChange(false);
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !busy && onOpenChange(v)}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle className="font-display text-2xl">
                            {title}
                        </DialogTitle>
                        <DialogDescription>
                            Choose how the customer pays. Enter confirms.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="rounded-lg bg-asphalt p-4 text-center text-white">
                        <p className="font-display text-sm tracking-widest text-zinc-400">
                            AMOUNT DUE
                        </p>
                        <p className="tabular font-display text-5xl font-extrabold text-racing">
                            {peso(amountDue)}
                        </p>
                    </div>

                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        {methods.map((m) => {
                            const Icon = methodIcon[m.type] ?? CreditCard;

                            return (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => setMethodId(m.id)}
                                    className={cn(
                                        'flex h-16 flex-col items-center justify-center gap-1 rounded-lg border-2 font-semibold transition',
                                        m.id === methodId
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border hover:border-primary/50',
                                    )}
                                >
                                    <Icon className="size-5" />
                                    {m.name}
                                </button>
                            );
                        })}
                    </div>

                    {isCash && (
                        <div className="space-y-2">
                            <Label htmlFor="tendered">Amount tendered</Label>
                            <Input
                                id="tendered"
                                autoFocus
                                inputMode="decimal"
                                placeholder={String(amountDue)}
                                value={tendered}
                                onChange={(e) =>
                                    setTendered(
                                        e.target.value.replace(/[^\d.]/g, ''),
                                    )
                                }
                                className="tabular h-14 text-right font-display text-3xl"
                            />
                            <div className="flex flex-wrap gap-2">
                                {quick.map((amount) => (
                                    <Button
                                        key={amount}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setTendered(String(amount))
                                        }
                                        className="tabular"
                                    >
                                        {amount === amountDue
                                            ? 'Exact'
                                            : peso(amount)}
                                    </Button>
                                ))}
                            </div>
                            <div className="flex items-center justify-between rounded-md bg-muted px-3 py-2">
                                <span className="font-display text-lg font-bold">
                                    Change
                                </span>
                                <span
                                    className={cn(
                                        'tabular font-display text-3xl font-extrabold',
                                        short
                                            ? 'text-destructive'
                                            : 'text-paid',
                                    )}
                                >
                                    {short ? 'Short' : peso(change)}
                                </span>
                            </div>
                        </div>
                    )}

                    {method?.requires_reference && (
                        <div className="space-y-2">
                            <Label htmlFor="reference">
                                {method.name} reference no.
                            </Label>
                            <Input
                                id="reference"
                                autoFocus={!isCash}
                                value={reference}
                                onChange={(e) => setReference(e.target.value)}
                                placeholder="e.g. 1009 234 5678"
                                className="h-12 text-lg"
                            />
                        </div>
                    )}

                    <Button
                        type="submit"
                        variant="racing"
                        size="xl"
                        className="w-full text-2xl"
                        disabled={busy || !method || short || missingRef}
                    >
                        {busy && <Spinner />}
                        Confirm {peso(amountDue)}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
