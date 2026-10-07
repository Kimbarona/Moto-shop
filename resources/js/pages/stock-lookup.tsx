import { Boxes, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { EmptyState } from '@/components/pos/empty-state';
import { selectClass } from '@/components/pos/product-finder';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useProductSearch } from '@/hooks/use-product-search';
import PosLayout from '@/layouts/pos-layout';
import { peso, qty } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Category, Make } from '@/types/pos';

export default function StockLookup({
    categories,
    makes,
}: {
    categories: Category[];
    makes: Make[];
}) {
    const [q, setQ] = useState('');
    const [debounced, setDebounced] = useState('');
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [makeId, setMakeId] = useState<number | null>(null);
    const [modelId, setModelId] = useState<number | null>(null);
    const {
        products: rows,
        nextPage,
        loading,
        error,
        loadMore,
    } = useProductSearch({
        q: debounced,
        category_id: categoryId,
        model_id: modelId,
        with_fits: true,
    });

    useEffect(() => {
        const t = setTimeout(() => setDebounced(q.trim()), 250);

        return () => clearTimeout(t);
    }, [q]);

    const models = useMemo(
        () => makes.find((m) => m.id === makeId)?.models ?? [],
        [makes, makeId],
    );

    return (
        <PosLayout title="Stock Lookup">
            <div className="space-y-4 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold">
                        Stock lookup
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        On hand, reserved for Pending orders, and available to
                        sell.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-64 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Name, SKU, part no., brand"
                            className="h-11 pl-10"
                        />
                    </div>
                    <select
                        className={selectClass}
                        value={categoryId ?? ''}
                        onChange={(e) =>
                            setCategoryId(
                                e.target.value ? Number(e.target.value) : null,
                            )
                        }
                        aria-label="Category"
                    >
                        <option value="">All categories</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={makeId ?? ''}
                        onChange={(e) => {
                            setMakeId(
                                e.target.value ? Number(e.target.value) : null,
                            );
                            setModelId(null);
                        }}
                        aria-label="Make"
                    >
                        <option value="">Fits any make</option>
                        {makes.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                    <select
                        className={selectClass}
                        value={modelId ?? ''}
                        disabled={!makeId}
                        onChange={(e) =>
                            setModelId(
                                e.target.value ? Number(e.target.value) : null,
                            )
                        }
                        aria-label="Model"
                    >
                        <option value="">Any model</option>
                        {models.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.name}
                            </option>
                        ))}
                    </select>
                </div>

                {error && <p className="text-destructive">{error}</p>}

                <div className="overflow-x-auto rounded-xl border bg-card">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                            <tr>
                                <th className="p-3">Part</th>
                                <th className="p-3">Fits</th>
                                <th className="p-3">Bin</th>
                                <th className="p-3 text-right">SRP</th>
                                <th className="p-3 text-right">On hand</th>
                                <th className="p-3 text-right">Reserved</th>
                                <th className="p-3 text-right">Available</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.map((p) => (
                                <tr key={p.id} className="hover:bg-muted/40">
                                    <td className="p-3">
                                        <p className="font-semibold">
                                            {p.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {[p.sku, p.brand, p.part_number]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </td>
                                    <td className="max-w-64 p-3 text-xs text-muted-foreground">
                                        {p.is_universal ? (
                                            <span className="font-semibold text-foreground">
                                                Universal
                                            </span>
                                        ) : (
                                            p.fits
                                                ?.map((f) => f.name)
                                                .join(', ') || '—'
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {p.bin_location ?? '—'}
                                    </td>
                                    <td className="tabular p-3 text-right">
                                        {peso(p.srp)}
                                    </td>
                                    <td className="tabular p-3 text-right">
                                        {qty(p.on_hand)} {p.unit}
                                    </td>
                                    <td
                                        className={cn(
                                            'tabular p-3 text-right',
                                            p.reserved > 0 &&
                                                'font-semibold text-amber-600 dark:text-hivis',
                                        )}
                                    >
                                        {qty(p.reserved)}
                                    </td>
                                    <td className="p-3 text-right">
                                        <span
                                            className={cn(
                                                'tabular rounded px-2 py-0.5 font-bold',
                                                p.available <= 0
                                                    ? 'bg-destructive text-white'
                                                    : p.low_stock
                                                      ? 'bg-hivis text-asphalt'
                                                      : 'bg-paid/15 text-paid',
                                            )}
                                        >
                                            {qty(p.available)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {loading && (
                        <div className="space-y-2 p-3">
                            {Array.from({ length: 6 }).map((_, i) => (
                                <Skeleton key={i} className="h-10" />
                            ))}
                        </div>
                    )}
                    {!loading && rows.length === 0 && (
                        <EmptyState icon={Boxes} title="No parts found" />
                    )}
                </div>

                {nextPage && !loading && (
                    <div className="text-center">
                        <Button variant="outline" onClick={loadMore}>
                            Load more
                        </Button>
                    </div>
                )}
            </div>
        </PosLayout>
    );
}
