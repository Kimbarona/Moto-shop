import { Bike, Plus, Search, UserPlus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { selectClass } from '@/components/pos/product-finder';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { api, toApiError } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { Customer, Make, Motorcycle } from '@/types/pos';

export type CustomerSelection = {
    customer: Customer | null;
    motorcycle: Motorcycle | null;
};

/**
 * Search customers by name / mobile / plate, or quick-add a customer and bike.
 */
export function CustomerPicker({
    makes,
    value,
    onChange,
    requireBike = false,
}: {
    makes: Make[];
    value: CustomerSelection;
    onChange: (value: CustomerSelection) => void;
    requireBike?: boolean;
}) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Customer[]>([]);
    const [loading, setLoading] = useState(false);
    const [adding, setAdding] = useState<null | 'customer' | 'bike'>(null);

    useEffect(() => {
        if (value.customer) {
            return;
        }

        const timer = setTimeout(async () => {
            setLoading(true);

            try {
                setResults(await api.customers(q));
            } catch (e) {
                toast.error(toApiError(e).first());
            } finally {
                setLoading(false);
            }
        }, 250);

        return () => clearTimeout(timer);
    }, [q, value.customer]);

    if (value.customer) {
        const customer = value.customer;

        return (
            <div className="space-y-3">
                <div className="flex items-center justify-between rounded-lg border bg-muted/50 p-3">
                    <div>
                        <p className="font-semibold">{customer.name}</p>
                        <p className="text-xs text-muted-foreground">
                            {customer.mobile ?? 'No mobile'}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange({ customer: null, motorcycle: null })
                        }
                    >
                        Change
                    </Button>
                </div>

                <div className="space-y-2">
                    <Label>Motorcycle{requireBike ? '' : ' (optional)'}</Label>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {customer.motorcycles.map((bike) => (
                            <button
                                key={bike.id}
                                type="button"
                                onClick={() =>
                                    onChange({
                                        customer,
                                        motorcycle:
                                            value.motorcycle?.id === bike.id
                                                ? null
                                                : bike,
                                    })
                                }
                                className={cn(
                                    'flex items-center gap-2 rounded-lg border-2 p-2 text-left',
                                    value.motorcycle?.id === bike.id
                                        ? 'border-primary bg-primary/10'
                                        : 'hover:border-primary/50',
                                )}
                            >
                                <Bike className="size-5 shrink-0 text-muted-foreground" />
                                <span>
                                    <span className="block font-display text-lg font-bold">
                                        {bike.plate_no ?? 'No plate'}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {[bike.model, bike.color]
                                            .filter(Boolean)
                                            .join(' · ') || 'Model not set'}
                                    </span>
                                </span>
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() => setAdding('bike')}
                            className="flex items-center justify-center gap-2 rounded-lg border-2 border-dashed p-2 text-sm text-muted-foreground hover:border-primary hover:text-primary"
                        >
                            <Plus className="size-4" /> Add motorcycle
                        </button>
                    </div>
                </div>

                {adding === 'bike' && (
                    <QuickAddBike
                        makes={makes}
                        onCancel={() => setAdding(null)}
                        onSave={async (bike) => {
                            const updated = await api.addMotorcycle(
                                customer.id,
                                bike,
                            );
                            const added =
                                updated.motorcycles[
                                    updated.motorcycles.length - 1
                                ] ?? null;
                            onChange({ customer: updated, motorcycle: added });
                            setAdding(null);
                        }}
                    />
                )}
            </div>
        );
    }

    if (adding === 'customer') {
        return (
            <QuickAddCustomer
                makes={makes}
                initialName={q}
                onCancel={() => setAdding(null)}
                onSave={(customer) => {
                    onChange({
                        customer,
                        motorcycle: customer.motorcycles[0] ?? null,
                    });
                    setAdding(null);
                }}
            />
        );
    }

    return (
        <div className="space-y-2">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    autoFocus
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Name, mobile or plate no."
                    className="h-11 pl-9"
                />
            </div>
            <div className="max-h-64 overflow-y-auto rounded-lg border">
                {loading && (
                    <p className="p-3 text-sm text-muted-foreground">
                        Searching…
                    </p>
                )}
                {!loading && results.length === 0 && (
                    <p className="p-3 text-sm text-muted-foreground">
                        No customer found.
                    </p>
                )}
                {results.map((customer) => (
                    <button
                        key={customer.id}
                        type="button"
                        onClick={() =>
                            onChange({
                                customer,
                                motorcycle:
                                    customer.motorcycles.length === 1
                                        ? customer.motorcycles[0]
                                        : null,
                            })
                        }
                        className="flex w-full items-center justify-between gap-2 border-b px-3 py-2 text-left last:border-b-0 hover:bg-muted"
                    >
                        <span>
                            <span className="block font-semibold">
                                {customer.name}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {customer.mobile ?? ''}
                            </span>
                        </span>
                        <span className="flex flex-wrap justify-end gap-1">
                            {customer.motorcycles.map((bike) => (
                                <span
                                    key={bike.id}
                                    className="rounded bg-muted px-1.5 font-display text-sm font-bold"
                                >
                                    {bike.plate_no ?? bike.model ?? 'Bike'}
                                </span>
                            ))}
                        </span>
                    </button>
                ))}
            </div>
            <Button
                type="button"
                variant="outline"
                className="w-full"
                onClick={() => setAdding('customer')}
            >
                <UserPlus /> New customer{q ? ` “${q}”` : ''}
            </Button>
        </div>
    );
}

function ModelSelect({
    makes,
    value,
    onChange,
}: {
    makes: Make[];
    value: number | null;
    onChange: (id: number | null) => void;
}) {
    return (
        <select
            className={cn(selectClass, 'w-full')}
            value={value ?? ''}
            onChange={(e) =>
                onChange(e.target.value ? Number(e.target.value) : null)
            }
            aria-label="Model"
        >
            <option value="">Select model…</option>
            {makes.map((make) => (
                <optgroup key={make.id} label={make.name}>
                    {make.models.map((model) => (
                        <option key={model.id} value={model.id}>
                            {make.name} {model.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </select>
    );
}

type BikeDraft = {
    plate_no: string;
    motorcycle_model_id: number | null;
    color: string;
    year: string;
};

function BikeFields({
    makes,
    bike,
    setBike,
}: {
    makes: Make[];
    bike: BikeDraft;
    setBike: (b: BikeDraft) => void;
}) {
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            <div className="space-y-1">
                <Label>Plate no.</Label>
                <Input
                    value={bike.plate_no}
                    onChange={(e) =>
                        setBike({
                            ...bike,
                            plate_no: e.target.value.toUpperCase(),
                        })
                    }
                    placeholder="ABC 1234 (blank if new)"
                />
            </div>
            <div className="space-y-1">
                <Label>Model</Label>
                <ModelSelect
                    makes={makes}
                    value={bike.motorcycle_model_id}
                    onChange={(id) =>
                        setBike({ ...bike, motorcycle_model_id: id })
                    }
                />
            </div>
            <div className="space-y-1">
                <Label>Color</Label>
                <Input
                    value={bike.color}
                    onChange={(e) =>
                        setBike({ ...bike, color: e.target.value })
                    }
                />
            </div>
            <div className="space-y-1">
                <Label>Year</Label>
                <Input
                    inputMode="numeric"
                    value={bike.year}
                    onChange={(e) =>
                        setBike({
                            ...bike,
                            year: e.target.value.replace(/\D/g, ''),
                        })
                    }
                />
            </div>
        </div>
    );
}

const emptyBike: BikeDraft = {
    plate_no: '',
    motorcycle_model_id: null,
    color: '',
    year: '',
};

function bikePayload(bike: BikeDraft) {
    return {
        plate_no: bike.plate_no.trim() || null,
        motorcycle_model_id: bike.motorcycle_model_id,
        color: bike.color.trim() || null,
        year: bike.year ? Number(bike.year) : null,
    };
}

function QuickAddCustomer({
    makes,
    initialName,
    onSave,
    onCancel,
}: {
    makes: Make[];
    initialName: string;
    onSave: (c: Customer) => void;
    onCancel: () => void;
}) {
    const [name, setName] = useState(/\d/.test(initialName) ? '' : initialName);
    const [mobile, setMobile] = useState(
        /^[\d\s+]+$/.test(initialName) ? initialName : '',
    );
    const [bike, setBike] = useState<BikeDraft>(emptyBike);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);
        setErrors({});

        try {
            const hasBike =
                bike.plate_no.trim() !== '' ||
                bike.motorcycle_model_id !== null;
            onSave(
                await api.createCustomer({
                    name,
                    mobile: mobile || null,
                    motorcycle: hasBike ? bikePayload(bike) : null,
                }),
            );
            toast.success('Customer added');
        } catch (e) {
            const err = toApiError(e);
            setErrors(err.errors);
            toast.error(err.first());
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <p className="font-display text-lg font-bold">New customer</p>
            <div className="grid gap-2 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label>Name</Label>
                    <Input
                        autoFocus
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        aria-invalid={!!errors.name}
                    />
                </div>
                <div className="space-y-1">
                    <Label>Mobile</Label>
                    <Input
                        value={mobile}
                        onChange={(e) => setMobile(e.target.value)}
                        placeholder="09xx xxx xxxx"
                    />
                </div>
            </div>
            <p className="pt-1 text-sm font-semibold">Motorcycle</p>
            <BikeFields makes={makes} bike={bike} setBike={setBike} />
            {errors['motorcycle.plate_no'] && (
                <p className="text-sm text-destructive">
                    {errors['motorcycle.plate_no'][0]}
                </p>
            )}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Back
                </Button>
                <Button
                    type="button"
                    onClick={save}
                    disabled={busy || name.trim() === ''}
                >
                    {busy && <Spinner />} Save customer
                </Button>
            </div>
        </div>
    );
}

function QuickAddBike({
    makes,
    onSave,
    onCancel,
}: {
    makes: Make[];
    onSave: (bike: Record<string, unknown>) => Promise<void>;
    onCancel: () => void;
}) {
    const [bike, setBike] = useState<BikeDraft>(emptyBike);
    const [busy, setBusy] = useState(false);

    const save = async () => {
        setBusy(true);

        try {
            await onSave(bikePayload(bike));
        } catch (e) {
            toast.error(toApiError(e).first());
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="space-y-3 rounded-lg border p-3">
            <p className="font-display text-lg font-bold">Add motorcycle</p>
            <BikeFields makes={makes} bike={bike} setBike={setBike} />
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={save}
                    disabled={
                        busy ||
                        (bike.plate_no.trim() === '' &&
                            bike.motorcycle_model_id === null)
                    }
                >
                    {busy && <Spinner />} Save motorcycle
                </Button>
            </div>
        </div>
    );
}
