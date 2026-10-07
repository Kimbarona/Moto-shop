import { usePage } from '@inertiajs/react';
import { Minus, Percent, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pos/empty-state';
import { selectClass } from '@/components/pos/product-finder';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { Cart, CartLine } from '@/hooks/use-cart';
import { peso, qty } from '@/lib/format';
import { orderDiscountPercent, reductionPercent } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import type { Catalog, DiscountType } from '@/types/pos';

type Props = {
    cart: Cart;
    catalog: Catalog;
    canEdit: boolean;
    canDiscount: boolean;
    showMechanics?: boolean;
};

export function CartLines({
    cart,
    catalog,
    canEdit,
    canDiscount,
    showMechanics,
}: Props) {
    if (cart.lines.length === 0) {
        return (
            <EmptyState
                icon={ShoppingCart}
                title="Cart is empty"
                className="py-16"
            >
                Tap a part, labor or package on the left to add it.
            </EmptyState>
        );
    }

    return (
        <ul className="divide-y">
            {cart.lines.map((line) => (
                <LineRow
                    // Re-mount when qty changes so the qty input shows the saved value.
                    key={`${line.key}:${line.qty}`}
                    line={line}
                    cart={cart}
                    catalog={catalog}
                    canEdit={canEdit}
                    canDiscount={canDiscount}
                    showMechanics={showMechanics}
                />
            ))}
        </ul>
    );
}

function LineRow({
    line,
    cart,
    catalog,
    canEdit,
    canDiscount,
    showMechanics,
}: Props & { line: CartLine }) {
    const step = line.allows_decimal ? 0.1 : 1;
    const [draft, setDraft] = useState(qty(line.qty));
    const isPackage = line.line_type === 'package';

    const commitQty = (value: number) => {
        const rounded = line.allows_decimal
            ? Math.round(value * 100) / 100
            : Math.round(value);

        if (!rounded || rounded <= 0) {
            setDraft(qty(line.qty));

            return;
        }

        if (rounded !== line.qty) {
            cart.update(line, { qty: rounded });
        }
    };

    return (
        <li className="py-2.5">
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                    <p className="leading-tight font-semibold">
                        {line.description}
                    </p>
                    <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                        {line.line_type === 'labor' && (
                            <span className="font-semibold text-sky-600 dark:text-sky-400">
                                LABOR
                            </span>
                        )}
                        {line.sku && <span>{line.sku}</span>}
                        {line.package && line.line_type !== 'package' && (
                            <span className="rounded bg-muted px-1">
                                {line.package}
                            </span>
                        )}
                        {isPackage && (
                            <span className="rounded bg-muted px-1">
                                Package — expands into parts & labor
                            </span>
                        )}
                        {line.max_qty !== null && line.line_type === 'part' && (
                            <span className="tabular">
                                max {qty(line.max_qty)}
                            </span>
                        )}
                    </p>
                </div>
                <span className="tabular font-display text-lg font-bold">
                    {peso(line.line_total)}
                </span>
                {canEdit && (
                    <button
                        type="button"
                        onClick={() => cart.remove(line)}
                        disabled={cart.busy}
                        className="grid size-8 place-items-center rounded text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        aria-label={`Remove ${line.description}`}
                    >
                        <Trash2 className="size-4" />
                    </button>
                )}
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                <div className="flex items-center">
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className="size-9 rounded-r-none"
                        disabled={
                            !canEdit ||
                            isPackage ||
                            cart.busy ||
                            line.qty <= step
                        }
                        onClick={() => commitQty(line.qty - step)}
                        aria-label="Less"
                    >
                        <Minus />
                    </Button>
                    <Input
                        value={draft}
                        inputMode="decimal"
                        disabled={!canEdit || isPackage || cart.busy}
                        onChange={(e) => setDraft(e.target.value)}
                        onBlur={() => commitQty(Number(draft))}
                        onKeyDown={(e) =>
                            e.key === 'Enter' &&
                            (e.target as HTMLInputElement).blur()
                        }
                        className="tabular h-9 w-16 rounded-none border-x-0 text-center"
                        aria-label="Quantity"
                    />
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        className="size-9 rounded-l-none"
                        disabled={
                            !canEdit ||
                            isPackage ||
                            cart.busy ||
                            (line.max_qty !== null &&
                                line.qty + step > line.max_qty)
                        }
                        onClick={() => commitQty(line.qty + step)}
                        aria-label="More"
                    >
                        <Plus />
                    </Button>
                </div>

                <span className="tabular text-sm text-muted-foreground">
                    × {peso(line.unit_price)}
                    {line.unit_price !== line.original_price && (
                        <span className="ml-1 line-through">
                            {peso(line.original_price)}
                        </span>
                    )}
                    {line.unit ? ` /${line.unit}` : ''}
                </span>

                {line.discount > 0 && (
                    <span className="tabular rounded bg-hivis/20 px-1.5 text-xs font-semibold text-amber-700 dark:text-hivis">
                        −{peso(line.discount)}
                        {line.discount_type === 'percent' &&
                            ` (${qty(line.discount_value)}%)`}
                    </span>
                )}

                <div className="ml-auto flex items-center gap-1">
                    {showMechanics && line.line_type === 'labor' && (
                        <select
                            className={cn(selectClass, 'h-8 text-xs')}
                            value={line.mechanic_id ?? ''}
                            disabled={!canEdit || cart.busy}
                            onChange={(e) =>
                                cart.update(line, {
                                    mechanic_id: e.target.value
                                        ? Number(e.target.value)
                                        : null,
                                })
                            }
                            aria-label="Mechanic"
                        >
                            <option value="">No mechanic</option>
                            {catalog.mechanics.map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name}
                                </option>
                            ))}
                        </select>
                    )}
                    {canDiscount && !isPackage && (
                        <PriceEditor
                            line={line}
                            cart={cart}
                            catalog={catalog}
                        />
                    )}
                </div>
            </div>
        </li>
    );
}

function PriceEditor({
    line,
    cart,
    catalog,
}: {
    line: CartLine;
    cart: Cart;
    catalog: Catalog;
}) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const [price, setPrice] = useState(String(line.unit_price));
    const [type, setType] = useState<DiscountType>(
        line.discount_type ?? 'percent',
    );
    const [value, setValue] = useState(
        line.discount_value ? String(line.discount_value) : '',
    );

    const openChange = (next: boolean) => {
        if (next) {
            setPrice(String(line.unit_price));
            setType(line.discount_type ?? 'percent');
            setValue(line.discount_value ? String(line.discount_value) : '');
        }

        setOpen(next);
    };

    const probe = {
        ...line,
        unit_price: Number(price) || 0,
        discount_type: Number(value) > 0 ? type : null,
        discount_value: Number(value) || 0,
    };
    const percent = reductionPercent(probe);
    const needsPin =
        auth.can.needsManagerPin &&
        percent > catalog.settings.cashierMaxDiscountPercent &&
        percent > reductionPercent(line);

    const apply = async () => {
        const changes: Record<string, unknown> = {};

        if (Number(price) !== line.unit_price) {
            changes.unit_price = Number(price);
        }

        if (
            Number(value || 0) !== line.discount_value ||
            (Number(value) > 0 && type !== line.discount_type)
        ) {
            changes.discount_type = Number(value) > 0 ? type : null;
            changes.discount_value = Number(value) || 0;
        }

        setOpen(false);

        if (Object.keys(changes).length) {
            await cart.update(line, changes);
        }
    };

    return (
        <Popover open={open} onOpenChange={openChange}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={cart.busy}
                    className="h-8 text-xs"
                >
                    <Percent className="size-3.5" /> Price / discount
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 space-y-3">
                <p className="font-display text-lg font-bold">
                    {line.description}
                </p>
                {catalog.settings.allowPriceOverride && (
                    <div className="space-y-1">
                        <Label htmlFor={`price-${line.key}`}>
                            Unit price (SRP {peso(line.original_price)})
                        </Label>
                        <Input
                            id={`price-${line.key}`}
                            inputMode="decimal"
                            value={price}
                            onChange={(e) => setPrice(e.target.value)}
                            className="tabular"
                        />
                    </div>
                )}
                <div className="space-y-1">
                    <Label>Line discount</Label>
                    <div className="flex gap-2">
                        <DiscountTypeToggle value={type} onChange={setType} />
                        <Input
                            inputMode="decimal"
                            value={value}
                            onChange={(e) => setValue(e.target.value)}
                            placeholder="0"
                            className="tabular"
                            aria-label="Discount value"
                        />
                    </div>
                </div>
                <p
                    className={cn(
                        'text-xs',
                        needsPin
                            ? 'font-semibold text-amber-600 dark:text-hivis'
                            : 'text-muted-foreground',
                    )}
                >
                    {percent > 0 ? `${qty(percent)}% off SRP.` : 'No discount.'}{' '}
                    {needsPin &&
                        `Above the ${qty(catalog.settings.cashierMaxDiscountPercent)}% cashier limit — a manager PIN will be asked.`}
                </p>
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button type="button" size="sm" onClick={apply}>
                        Apply
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}

export function DiscountTypeToggle({
    value,
    onChange,
}: {
    value: DiscountType;
    onChange: (type: DiscountType) => void;
}) {
    return (
        <div className="flex shrink-0 rounded-md border p-0.5">
            {(['amount', 'percent'] as const).map((t) => (
                <button
                    key={t}
                    type="button"
                    onClick={() => onChange(t)}
                    className={cn(
                        'h-8 w-9 rounded font-bold',
                        value === t
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground',
                    )}
                >
                    {t === 'amount' ? '₱' : '%'}
                </button>
            ))}
        </div>
    );
}

export function CartTotals({
    cart,
    catalog,
    canDiscount,
}: {
    cart: Cart;
    catalog: Catalog;
    canDiscount: boolean;
}) {
    const { settings } = catalog;
    const t = cart.totals;

    return (
        <div className="space-y-1 text-sm">
            <Row label="Subtotal" value={peso(t.subtotal)} />
            <div className="flex items-center justify-between">
                <span className="flex items-center gap-1">
                    Discount
                    {canDiscount && cart.lines.length > 0 && (
                        <OrderDiscountEditor cart={cart} catalog={catalog} />
                    )}
                </span>
                <span className="tabular">
                    {t.discount_total > 0
                        ? `−${peso(t.discount_total)}`
                        : peso(0)}
                </span>
            </div>
            {settings.vatRegistered && (
                <>
                    <Row muted label="VATable sales" value={peso(t.vatable)} />
                    <Row
                        muted
                        label={`VAT ${qty(settings.vatRate)}%${settings.pricesVatInclusive ? ' (included)' : ''}`}
                        value={peso(t.vat_amount)}
                    />
                </>
            )}
            {cart.order && cart.order.return_credit > 0 && (
                <Row
                    label="Return credit"
                    value={`−${peso(cart.order.return_credit)}`}
                />
            )}
            <div className="flex items-end justify-between border-t pt-2">
                <span className="font-display text-xl font-bold">Total</span>
                <span className="tabular font-display text-4xl leading-none font-extrabold text-primary">
                    {peso(t.amount_due)}
                </span>
            </div>
        </div>
    );
}

function Row({
    label,
    value,
    muted,
}: {
    label: string;
    value: string;
    muted?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-center justify-between',
                muted && 'text-xs text-muted-foreground',
            )}
        >
            <span>{label}</span>
            <span className="tabular">{value}</span>
        </div>
    );
}

function OrderDiscountEditor({
    cart,
    catalog,
}: {
    cart: Cart;
    catalog: Catalog;
}) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const [type, setType] = useState<DiscountType>(
        cart.orderDiscount.type ?? 'percent',
    );
    const [value, setValue] = useState(
        cart.orderDiscount.value ? String(cart.orderDiscount.value) : '',
    );

    const openChange = (next: boolean) => {
        if (next) {
            setType(cart.orderDiscount.type ?? 'percent');
            setValue(
                cart.orderDiscount.value
                    ? String(cart.orderDiscount.value)
                    : '',
            );
        }

        setOpen(next);
    };

    const percent = orderDiscountPercent(cart.lines, type, Number(value) || 0);
    const needsPin =
        auth.can.needsManagerPin &&
        percent > catalog.settings.cashierMaxDiscountPercent;

    return (
        <Popover open={open} onOpenChange={openChange}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="text-xs font-semibold text-primary underline-offset-2 hover:underline"
                    disabled={cart.busy}
                >
                    {cart.orderDiscount.value > 0 ? 'edit' : '+ add'}
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-64 space-y-3">
                <p className="font-display text-lg font-bold">Order discount</p>
                <div className="flex gap-2">
                    <DiscountTypeToggle value={type} onChange={setType} />
                    <Input
                        autoFocus
                        inputMode="decimal"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        placeholder="0"
                        className="tabular"
                        aria-label="Order discount"
                    />
                </div>
                {needsPin && (
                    <p className="text-xs font-semibold text-amber-600 dark:text-hivis">
                        Above the{' '}
                        {qty(catalog.settings.cashierMaxDiscountPercent)}%
                        cashier limit — a manager PIN will be asked.
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            setOpen(false);
                            cart.setOrderDiscount(null, 0);
                        }}
                    >
                        Remove
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => {
                            setOpen(false);
                            cart.setOrderDiscount(type, Number(value) || 0);
                        }}
                    >
                        Apply
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
