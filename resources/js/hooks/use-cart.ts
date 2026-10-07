import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { usePin } from '@/hooks/use-pin';
import { api, toApiError } from '@/lib/api';
import type { CartLinePayload } from '@/lib/api';
import { priceLine, totals as computeTotals } from '@/lib/pricing';
import type { Totals } from '@/lib/pricing';
import type {
    DiscountType,
    LaborService,
    Order,
    PosSettings,
    Product,
    ServicePackage,
} from '@/types/pos';

export type CartLine = {
    key: string;
    id?: number;
    line_type: 'part' | 'labor' | 'package';
    product_id?: number | null;
    service_id?: number | null;
    package_id?: number | null;
    package?: string | null;
    description: string;
    sku?: string | null;
    unit?: string | null;
    allows_decimal: boolean;
    qty: number;
    original_price: number;
    unit_price: number;
    discount_type: DiscountType | null;
    discount_value: number;
    discount: number;
    line_total: number;
    net: number;
    max_qty: number | null;
    mechanic_id?: number | null;
    mechanic?: string | null;
    commission?: number;
};

export type LineChanges = Partial<
    Pick<
        CartLine,
        | 'qty'
        | 'unit_price'
        | 'discount_type'
        | 'discount_value'
        | 'mechanic_id'
    >
>;

export type Cart = {
    kind: 'local' | 'order';
    order: Order | null;
    lines: CartLine[];
    totals: Totals;
    orderDiscount: { type: DiscountType | null; value: number };
    busy: boolean;
    addPart: (product: Product, qty?: number) => Promise<void>;
    addLabor: (service: LaborService) => Promise<void>;
    addPackage: (pkg: ServicePackage) => Promise<void>;
    update: (line: CartLine, changes: LineChanges) => Promise<void>;
    remove: (line: CartLine) => Promise<void>;
    setOrderDiscount: (
        type: DiscountType | null,
        value: number,
    ) => Promise<void>;
    clear: () => void;
    replaceOrder: (order: Order) => void;
    payload: () => CartLinePayload[];
};

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

function fail(error: unknown) {
    toast.error(toApiError(error).first());
}

/**
 * Walk-in cart kept in the browser (per user) until it is paid or saved as Pending.
 */
export function useLocalCart(userId: number, settings: PosSettings): Cart {
    const storageKey = `pos-cart-${userId}`;

    const [state, setState] = useState<{
        lines: CartLine[];
        orderDiscount: { type: DiscountType | null; value: number };
    }>(() => {
        try {
            const saved = localStorage.getItem(storageKey);

            if (saved) {
                return JSON.parse(saved);
            }
        } catch {
            // ignore broken / unavailable storage
        }

        return { lines: [], orderDiscount: { type: null, value: 0 } };
    });

    useEffect(() => {
        try {
            localStorage.setItem(storageKey, JSON.stringify(state));
        } catch {
            // ignore
        }
    }, [state, storageKey]);

    const reprice = (line: CartLine): CartLine => {
        const priced = priceLine(line);

        return {
            ...line,
            discount: priced.discount,
            line_total: priced.line_total,
            net: priced.line_total,
        };
    };

    // Plain functions reading the current state; the React Compiler memoizes them.
    // Side effects (toasts) stay out of setState updaters.
    const setLines = (lines: CartLine[]) =>
        setState((s) => ({ ...s, lines: lines.map(reprice) }));

    const blankLine = {
        discount_type: null,
        discount_value: 0,
        discount: 0,
        line_total: 0,
        net: 0,
    } as const;

    const addPart = async (product: Product, qty = 1) => {
        const lines = state.lines;
        const existing = lines.find(
            (l) => l.line_type === 'part' && l.product_id === product.id,
        );
        const max = settings.allowNegativeStock ? null : product.available;

        if (max !== null && (existing?.qty ?? 0) + qty > max) {
            toast.error(
                `Only ${max} ${product.unit ?? ''} of ${product.name} available.`,
            );

            return;
        }

        if (existing) {
            setLines(
                lines.map((l) =>
                    l === existing ? { ...l, qty: round2(l.qty + qty) } : l,
                ),
            );

            return;
        }

        setLines([
            ...lines,
            {
                ...blankLine,
                key: `p${product.id}`,
                line_type: 'part',
                product_id: product.id,
                description: product.name,
                sku: product.sku,
                unit: product.unit,
                allows_decimal: product.allows_decimal,
                qty,
                original_price: product.srp,
                unit_price: product.srp,
                max_qty: max,
            },
        ]);
    };

    const addLabor = async (service: LaborService) => {
        setLines([
            ...state.lines,
            {
                ...blankLine,
                key: `l${service.id}-${Date.now()}`,
                line_type: 'labor',
                service_id: service.id,
                description: service.name,
                allows_decimal: false,
                qty: 1,
                original_price: service.labor_fee,
                unit_price: service.labor_fee,
                max_qty: null,
            },
        ]);
    };

    const addPackage = async (pkg: ServicePackage) => {
        const price =
            pkg.package_price !== null && pkg.package_price < pkg.regular_price
                ? pkg.package_price
                : pkg.regular_price;

        setLines([
            ...state.lines,
            {
                ...blankLine,
                key: `k${pkg.id}-${Date.now()}`,
                line_type: 'package',
                package_id: pkg.id,
                package: pkg.name,
                description: pkg.name,
                allows_decimal: false,
                qty: 1,
                original_price: price,
                unit_price: price,
                max_qty: 1,
            },
        ]);
    };

    const update = async (line: CartLine, changes: LineChanges) => {
        if (
            changes.qty !== undefined &&
            line.max_qty !== null &&
            changes.qty > line.max_qty
        ) {
            toast.error(`Only ${line.max_qty} available.`);

            return;
        }

        setLines(
            state.lines.map((l) =>
                l.key === line.key ? { ...l, ...changes } : l,
            ),
        );
    };

    const remove = async (line: CartLine) => {
        setLines(state.lines.filter((l) => l.key !== line.key));
    };

    const setOrderDiscount = async (
        type: DiscountType | null,
        value: number,
    ) => {
        setState((s) => ({
            ...s,
            orderDiscount: { type: value > 0 ? type : null, value },
        }));
    };

    const clear = () =>
        setState({ lines: [], orderDiscount: { type: null, value: 0 } });

    const totals = useMemo(
        () => computeTotals(state.lines, state.orderDiscount, settings),
        [state, settings],
    );

    const payload = (): CartLinePayload[] =>
        state.lines.map((l) =>
            l.line_type === 'package'
                ? { type: 'package', package_id: l.package_id ?? undefined }
                : {
                      type: l.line_type,
                      product_id: l.product_id ?? undefined,
                      service_id: l.service_id ?? undefined,
                      qty: l.qty,
                      unit_price:
                          l.unit_price !== l.original_price
                              ? l.unit_price
                              : undefined,
                      discount_type:
                          l.discount_value > 0 ? l.discount_type : null,
                      discount_value:
                          l.discount_value > 0 ? l.discount_value : null,
                  },
        );

    return {
        kind: 'local',
        order: null,
        lines: state.lines,
        totals,
        orderDiscount: state.orderDiscount,
        busy: false,
        addPart,
        addLabor,
        addPackage,
        update,
        remove,
        setOrderDiscount,
        clear,
        replaceOrder: () => {},
        payload,
    };
}

/**
 * A Pending order on the server: every change is saved immediately.
 */
export function useOrderCart(initial: Order): Cart {
    const [order, setOrder] = useState<Order>(initial);
    const [busy, setBusy] = useState(false);
    const { withPin } = usePin();

    // New props from the server (e.g. a reload) replace the local copy.
    const [previous, setPrevious] = useState(initial);

    if (initial !== previous) {
        setPrevious(initial);
        setOrder(initial);
    }

    const run = useCallback(
        async (fn: (pin?: string) => Promise<Order>) => {
            setBusy(true);

            try {
                const result = await withPin(fn);

                if (result) {
                    setOrder(result);
                }
            } catch (error) {
                fail(error);
            } finally {
                setBusy(false);
            }
        },
        [withPin],
    );

    const lines: CartLine[] = useMemo(
        () =>
            order.items.map((item) => ({
                ...item,
                key: `i${item.id}`,
                id: item.id,
                package_id: null,
            })),
        [order.items],
    );

    return {
        kind: 'order',
        order,
        lines,
        totals: {
            subtotal: order.subtotal,
            discount_total: order.discount_total,
            vatable: order.vatable,
            vat_amount: order.vat_amount,
            total: order.total,
            amount_due: order.amount_due,
        },
        orderDiscount: {
            type: order.discount_type,
            value: order.discount_value,
        },
        busy,
        addPart: (product, qty = 1) =>
            run(() =>
                api.addItem(order.id, {
                    type: 'part',
                    product_id: product.id,
                    qty,
                }),
            ),
        addLabor: (service) =>
            run(() =>
                api.addItem(order.id, {
                    type: 'labor',
                    service_id: service.id,
                }),
            ),
        addPackage: (pkg) =>
            run(() =>
                api.addItem(order.id, { type: 'package', package_id: pkg.id }),
            ),
        update: (line, changes) =>
            run((pin) =>
                api.updateItem(order.id, line.id!, { ...changes, pin }),
            ),
        remove: (line) => run(() => api.removeItem(order.id, line.id!)),
        setOrderDiscount: (type, value) =>
            run((pin) =>
                api.setDiscount(order.id, {
                    discount_type: value > 0 ? type : null,
                    discount_value: value,
                    pin,
                }),
            ),
        clear: () => {},
        replaceOrder: setOrder,
        payload: () => [],
    };
}
