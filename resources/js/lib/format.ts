const pesoFormatter = new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/** ₱1,234.00 (negative: -₱1,234.00) */
export function peso(value: number | string | null | undefined): string {
    const amount = Number(value ?? 0);
    const formatted = `₱${pesoFormatter.format(Math.abs(amount))}`;

    return amount < 0 ? `-${formatted}` : formatted;
}

/** 2, 0.8, 1.25 — no trailing zeros. */
export function qty(value: number | string | null | undefined): string {
    const n = Number(value ?? 0);

    return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100);
}

const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

function manila(value: string | Date): Date {
    const date = typeof value === 'string' ? new Date(value) : value;
    // Render in Asia/Manila regardless of the browser's zone.
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        hour12: false,
    }).formatToParts(date);
    const get = (type: string) =>
        Number(parts.find((p) => p.type === type)?.value ?? 0);

    return new Date(
        get('year'),
        get('month') - 1,
        get('day'),
        get('hour') % 24,
        get('minute'),
    );
}

/** M d, Y h:i A — "Oct 07, 2026 03:45 PM" */
export function dateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const d = manila(value);
    const h = d.getHours() % 12 || 12;
    const ampm = d.getHours() < 12 ? 'AM' : 'PM';

    return `${months[d.getMonth()]} ${String(d.getDate()).padStart(2, '0')}, ${d.getFullYear()} ${String(h).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')} ${ampm}`;
}

/** M d, Y */
export function date(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const d = manila(value);

    return `${months[d.getMonth()]} ${String(d.getDate()).padStart(2, '0')}, ${d.getFullYear()}`;
}

/** "3h 20m", "2d 4h", "12m" since the given time. */
export function age(
    value: string | null | undefined,
    now: number = Date.now(),
): string {
    if (!value) {
        return '—';
    }

    const minutes = Math.max(
        0,
        Math.floor((now - new Date(value).getTime()) / 60000),
    );

    if (minutes < 60) {
        return `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h ${minutes % 60}m`;
    }

    return `${Math.floor(hours / 24)}d ${hours % 24}h`;
}

export function hoursSince(
    value: string | null | undefined,
    now: number = Date.now(),
): number {
    return value ? (now - new Date(value).getTime()) / 3_600_000 : 0;
}
