import { useEffect, useState } from 'react';
import { api, toApiError } from '@/lib/api';
import type { Product } from '@/types/pos';

type Filters = {
    q?: string;
    category_id?: number | null;
    model_id?: number | null;
    with_fits?: boolean;
};

type State = {
    key: string | null;
    refresh: unknown;
    products: Product[];
    nextPage: number | null;
    error: string | null;
    loadingMore: boolean;
};

function params(filters: Filters, page: number) {
    return {
        q: filters.q || undefined,
        category_id: filters.category_id ?? undefined,
        model_id: filters.model_id ?? undefined,
        with_fits: filters.with_fits ? 1 : undefined,
        page,
    };
}

/**
 * Product search with results keyed by their filters: "loading" is derived (results for
 * the current filters have not arrived yet), stale responses are ignored, and the list
 * reloads whenever `refreshKey` changes (e.g. after the cart changed availability).
 */
export function useProductSearch(filters: Filters, refreshKey?: unknown) {
    const key = JSON.stringify(filters);
    const [state, setState] = useState<State>({
        key: null,
        refresh: undefined,
        products: [],
        nextPage: null,
        error: null,
        loadingMore: false,
    });

    useEffect(() => {
        let cancelled = false;
        const current: Filters = JSON.parse(key);

        api.products(params(current, 1))
            .then((result) => {
                if (!cancelled) {
                    setState({
                        key,
                        refresh: refreshKey,
                        products: result.data,
                        nextPage: result.next_page,
                        error: null,
                        loadingMore: false,
                    });
                }
            })
            .catch((error) => {
                if (!cancelled) {
                    setState((s) => ({
                        ...s,
                        key,
                        refresh: refreshKey,
                        error: toApiError(error).first(),
                    }));
                }
            });

        return () => {
            cancelled = true;
        };
    }, [key, refreshKey]);

    const loadMore = () => {
        if (!state.nextPage || state.loadingMore) {
            return;
        }

        const page = state.nextPage;
        setState((s) => ({ ...s, loadingMore: true }));

        api.products(params(filters, page))
            .then((result) =>
                setState((s) =>
                    s.key === key
                        ? {
                              ...s,
                              products: [...s.products, ...result.data],
                              nextPage: result.next_page,
                              loadingMore: false,
                          }
                        : s,
                ),
            )
            .catch((error) =>
                setState((s) => ({
                    ...s,
                    loadingMore: false,
                    error: toApiError(error).first(),
                })),
            );
    };

    return {
        products: state.products,
        nextPage: state.nextPage,
        error: state.error,
        loading:
            state.key !== key ||
            state.refresh !== refreshKey ||
            state.loadingMore,
        firstLoad: state.key === null,
        loadMore,
    };
}
