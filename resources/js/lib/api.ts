import axios, { AxiosError } from 'axios';
import type { Customer, Order, Product } from '@/types/pos';

/**
 * JSON client for the POS / Service Board. Laravel's XSRF-TOKEN cookie is sent back
 * automatically by axios as the X-XSRF-TOKEN header.
 */
export const http = axios.create({
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
});

export type ApiErrors = Record<string, string[]>;

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: ApiErrors = {},
    ) {
        super(message);
    }

    /** The server wants a manager PIN (422 with an error on "pin"). */
    get needsPin(): boolean {
        return this.status === 422 && 'pin' in this.errors;
    }

    first(): string {
        const first = Object.values(this.errors)[0]?.[0];

        return first ?? this.message;
    }
}

export function toApiError(error: unknown): ApiError {
    if (error instanceof ApiError) {
        return error;
    }

    if (error instanceof AxiosError) {
        const status = error.response?.status ?? 0;
        const data = error.response?.data as
            { message?: string; errors?: ApiErrors } | undefined;

        if (status === 419) {
            return new ApiError(
                'Your session expired. Reload the page and sign in again.',
                status,
            );
        }

        if (status === 403) {
            return new ApiError(
                data?.message && data.message !== 'This action is unauthorized.'
                    ? data.message
                    : 'You are not allowed to do that.',
                status,
            );
        }

        if (status === 0) {
            return new ApiError(
                'Cannot reach the server. Check the connection.',
                status,
            );
        }

        return new ApiError(
            data?.message ?? 'Something went wrong.',
            status,
            data?.errors ?? {},
        );
    }

    return new ApiError(
        error instanceof Error ? error.message : 'Something went wrong.',
        0,
    );
}

async function call<T>(promise: Promise<{ data: T }>): Promise<T> {
    try {
        return (await promise).data;
    } catch (error) {
        throw toApiError(error);
    }
}

export type CartLinePayload = {
    type: 'part' | 'labor' | 'package';
    product_id?: number;
    service_id?: number;
    package_id?: number;
    qty?: number;
    unit_price?: number;
    discount_type?: string | null;
    discount_value?: number | null;
    mechanic_id?: number | null;
};

export type PaymentPayload = {
    payment_method_id: number | null;
    amount_tendered?: number | null;
    reference_no?: string | null;
};

export const api = {
    products: (params: Record<string, unknown>) =>
        call<{ data: Product[]; next_page: number | null }>(
            http.get('/pos/api/products', { params }),
        ),

    customers: (q: string) =>
        call<Customer[]>(http.get('/pos/api/customers', { params: { q } })),
    createCustomer: (payload: Record<string, unknown>) =>
        call<Customer>(http.post('/pos/api/customers', payload)),
    addMotorcycle: (customerId: number, payload: Record<string, unknown>) =>
        call<Customer>(
            http.post(`/pos/api/customers/${customerId}/motorcycles`, payload),
        ),

    createOrder: (payload: Record<string, unknown>) =>
        call<Order>(http.post('/pos/api/orders', payload)),
    order: (id: number) => call<Order>(http.get(`/pos/api/orders/${id}`)),
    updateOrder: (id: number, payload: Record<string, unknown>) =>
        call<Order>(http.patch(`/pos/api/orders/${id}`, payload)),
    addItem: (id: number, payload: Record<string, unknown>) =>
        call<Order>(http.post(`/pos/api/orders/${id}/items`, payload)),
    updateItem: (
        id: number,
        itemId: number,
        payload: Record<string, unknown>,
    ) =>
        call<Order>(
            http.patch(`/pos/api/orders/${id}/items/${itemId}`, payload),
        ),
    removeItem: (id: number, itemId: number) =>
        call<Order>(http.delete(`/pos/api/orders/${id}/items/${itemId}`)),
    setDiscount: (id: number, payload: Record<string, unknown>) =>
        call<Order>(http.put(`/pos/api/orders/${id}/discount`, payload)),
    setServiceStatus: (id: number, service_status: string) =>
        call<Order>(
            http.put(`/pos/api/orders/${id}/service-status`, {
                service_status,
            }),
        ),
    pay: (id: number, payload: PaymentPayload) =>
        call<Order>(http.post(`/pos/api/orders/${id}/pay`, payload)),
    voidOrder: (id: number, payload: { reason: string; pin?: string | null }) =>
        call<Order>(http.post(`/pos/api/orders/${id}/void`, payload)),
    checkout: (payload: Record<string, unknown>) =>
        call<Order>(http.post('/pos/api/checkout', payload)),
};

export const urls = {
    pos: (orderId?: number) => (orderId ? `/pos?order=${orderId}` : '/pos'),
    job: (orderId: number) => `/jobs/${orderId}`,
    order: (orderId: number) => `/orders/${orderId}`,
    openOrders: '/orders/open',
    serviceBoard: '/service-board',
    stockLookup: '/stock-lookup',
    customer: (id: number) => `/customers/${id}`,
    mySummary: '/my-summary',
    returns: '/returns/new',
    slip: (orderId: number, reprint = false) =>
        `/print/slip/${orderId}${reprint ? '?reprint=1' : ''}`,
    jobTicket: (orderId: number, reprint = false) =>
        `/print/job-ticket/${orderId}${reprint ? '?reprint=1' : ''}`,
};

/** Open a print document in a small window; it prints itself and closes. */
export function openPrint(url: string): void {
    const win = window.open(url, '_blank', 'width=420,height=720');

    if (!win) {
        window.location.href = url;
    }
}
