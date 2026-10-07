import type { DiscountType, PosSettings } from '@/types/pos';

/**
 * Client-side mirror of App\Services\Orders\PricingService, used only to show totals for
 * the local walk-in cart before it is sent. The server recalculates everything.
 */

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

export function lineDiscount(
    gross: number,
    type: DiscountType | null,
    value: number,
): number {
    let discount = 0;

    if (type === 'percent') {
        discount = (gross * Math.min(100, Math.max(0, value))) / 100;
    } else if (type === 'amount') {
        discount = Math.max(0, value);
    }

    return round2(Math.min(gross, discount));
}

export type PricedLine = {
    qty: number;
    unit_price: number;
    original_price: number;
    discount_type: DiscountType | null;
    discount_value: number;
};

export function priceLine<T extends PricedLine>(
    line: T,
): T & { gross: number; discount: number; line_total: number } {
    const gross = round2(line.qty * line.unit_price);
    const discount = lineDiscount(
        gross,
        line.discount_type,
        line.discount_value,
    );

    return { ...line, gross, discount, line_total: round2(gross - discount) };
}

/** Reduction of a line vs. its original price, in % (what the cashier limit checks). */
export function reductionPercent(line: PricedLine): number {
    const original = line.original_price * line.qty;

    if (original <= 0) {
        return 0;
    }

    return round2(
        Math.max(0, ((original - priceLine(line).line_total) / original) * 100),
    );
}

export type Totals = {
    subtotal: number;
    discount_total: number;
    vatable: number;
    vat_amount: number;
    total: number;
    amount_due: number;
};

export function totals(
    lines: PricedLine[],
    orderDiscount: { type: DiscountType | null; value: number },
    settings: Pick<
        PosSettings,
        'vatRegistered' | 'vatRate' | 'pricesVatInclusive'
    >,
): Totals {
    const priced = lines.map(priceLine);
    const subtotal = round2(priced.reduce((sum, l) => sum + l.gross, 0));
    const lineDiscounts = round2(
        priced.reduce((sum, l) => sum + l.discount, 0),
    );
    const afterLines = round2(subtotal - lineDiscounts);
    const orderOff = lineDiscount(
        afterLines,
        orderDiscount.type,
        orderDiscount.value,
    );
    const net = round2(afterLines - orderOff);

    let vatable = 0;
    let vat = 0;
    let total = net;

    if (settings.vatRegistered) {
        const rate = settings.vatRate / 100;

        if (settings.pricesVatInclusive) {
            vatable = round2(net / (1 + rate));
            vat = round2(net - vatable);
        } else {
            vatable = net;
            vat = round2(net * rate);
            total = round2(net + vat);
        }
    }

    return {
        subtotal,
        discount_total: round2(lineDiscounts + orderOff),
        vatable,
        vat_amount: vat,
        total,
        amount_due: total,
    };
}

export function orderDiscountPercent(
    lines: PricedLine[],
    type: DiscountType | null,
    value: number,
): number {
    const base = round2(
        lines.map(priceLine).reduce((sum, l) => sum + l.line_total, 0),
    );

    return base > 0
        ? round2((lineDiscount(base, type, value) / base) * 100)
        : 0;
}
