import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { CustomerPicker } from '@/components/pos/customer-picker';
import type { CustomerSelection } from '@/components/pos/customer-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { api, toApiError } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { Catalog, Order } from '@/types/pos';

export type ServiceDetails = {
    customer_id: number | null;
    customer_motorcycle_id: number | null;
    odometer_in: number | null;
    complaint: string | null;
    mechanic_ids: number[];
};

/**
 * New service order (or edit the details of an open one): customer & bike, odometer,
 * complaint and mechanics.
 */
type ServiceOrderDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    catalog: Catalog;
    order?: Order | null;
    onSaved: (order: Order) => void;
};

/** Mounted only while open, so the form starts from the order (or empty) each time. */
export function ServiceOrderDialog(props: ServiceOrderDialogProps) {
    return props.open ? <ServiceOrderForm {...props} /> : null;
}

function ServiceOrderForm({
    open,
    onOpenChange,
    catalog,
    order,
    onSaved,
}: ServiceOrderDialogProps) {
    const [selection, setSelection] = useState<CustomerSelection>({
        customer: null,
        motorcycle: null,
    });
    const [odometer, setOdometer] = useState(
        order?.odometer_in ? String(order.odometer_in) : '',
    );
    const [complaint, setComplaint] = useState(order?.complaint ?? '');
    const [mechanicIds, setMechanicIds] = useState<number[]>(
        order?.mechanics.map((m) => m.id) ?? [],
    );
    const [busy, setBusy] = useState(false);

    // Editing: load the customer with their bikes so the bike can be changed.
    useEffect(() => {
        if (order?.customer) {
            api.customers(order.customer.name)
                .then((list) => {
                    const customer =
                        list.find((c) => c.id === order.customer?.id) ?? null;
                    setSelection({
                        customer,
                        motorcycle:
                            customer?.motorcycles.find(
                                (b) => b.id === order.motorcycle?.id,
                            ) ?? null,
                    });
                })
                .catch(() => {});
        }
    }, [order]);

    const save = async () => {
        if (!selection.customer) {
            toast.error('Pick or add the customer first.');

            return;
        }

        const details: ServiceDetails = {
            customer_id: selection.customer.id,
            customer_motorcycle_id: selection.motorcycle?.id ?? null,
            odometer_in: odometer ? Number(odometer) : null,
            complaint: complaint.trim() || null,
            mechanic_ids: mechanicIds,
        };

        setBusy(true);

        try {
            const saved = order
                ? await api.updateOrder(order.id, details)
                : await api.createOrder({ type: 'service', ...details });
            onSaved(saved);
            onOpenChange(false);
        } catch (e) {
            toast.error(toApiError(e).first());
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !busy && onOpenChange(v)}>
            <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="font-display text-2xl">
                        {order
                            ? `Job details · ${order.order_no}`
                            : 'New service order'}
                    </DialogTitle>
                    <DialogDescription>
                        Customer, motorcycle, odometer, complaint and mechanic.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <CustomerPicker
                        makes={catalog.makes}
                        value={selection}
                        onChange={setSelection}
                        requireBike
                    />

                    <div className="grid gap-3 sm:grid-cols-[160px_1fr]">
                        <div className="space-y-1">
                            <Label htmlFor="odometer">Odometer (km)</Label>
                            <Input
                                id="odometer"
                                inputMode="numeric"
                                value={odometer}
                                onChange={(e) =>
                                    setOdometer(
                                        e.target.value.replace(/\D/g, ''),
                                    )
                                }
                                placeholder={
                                    selection.motorcycle?.last_odometer
                                        ? `last ${selection.motorcycle.last_odometer}`
                                        : ''
                                }
                                className="tabular"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="complaint">
                                Complaint / request
                            </Label>
                            <textarea
                                id="complaint"
                                rows={2}
                                value={complaint}
                                onChange={(e) => setComplaint(e.target.value)}
                                placeholder="e.g. PMS, brakes squeak, hard starting…"
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label>Mechanic</Label>
                        <div className="flex flex-wrap gap-2">
                            {catalog.mechanics.map((m) => {
                                const active = mechanicIds.includes(m.id);

                                return (
                                    <button
                                        key={m.id}
                                        type="button"
                                        onClick={() =>
                                            setMechanicIds(
                                                active
                                                    ? mechanicIds.filter(
                                                          (id) => id !== m.id,
                                                      )
                                                    : [...mechanicIds, m.id],
                                            )
                                        }
                                        className={cn(
                                            'h-10 rounded-full border-2 px-4 font-semibold',
                                            active
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'hover:border-primary/50',
                                        )}
                                    >
                                        {m.name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={busy}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant="racing"
                        size="lg"
                        onClick={save}
                        disabled={busy || !selection.customer}
                    >
                        {busy && <Spinner />}{' '}
                        {order ? 'Save details' : 'Open job'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
