import { Boxes, PackageOpen, Search, Wrench, X } from 'lucide-react';
import type { RefObject } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { CategoryIcon } from '@/components/pos/category-icon';
import { EmptyState } from '@/components/pos/empty-state';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useProductSearch } from '@/hooks/use-product-search';
import { peso, qty } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    Catalog,
    LaborService,
    Product,
    ServicePackage,
} from '@/types/pos';

type Props = {
    catalog: Catalog;
    onAddPart: (product: Product) => void;
    onAddLabor: (service: LaborService) => void;
    onAddPackage: (pkg: ServicePackage) => void;
    /** Qty of a product already in the (local) cart, to show what is still free. */
    inCart?: (productId: number) => number;
    disabled?: boolean;
    defaultModelId?: number | null;
    /** Any value; when it changes, availability is reloaded. */
    refreshKey?: unknown;
    searchRef?: RefObject<HTMLInputElement | null>;
};

export const selectClass =
    'h-10 rounded-md border border-input bg-background px-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring outline-none disabled:opacity-50';

export function ProductFinder({
    catalog,
    onAddPart,
    onAddLabor,
    onAddPackage,
    inCart,
    disabled,
    defaultModelId,
    refreshKey,
    searchRef,
}: Props) {
    const [tab, setTab] = useState('parts');
    const [query, setQuery] = useState('');
    const [debounced, setDebounced] = useState('');
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [modelId, setModelId] = useState<number | null>(
        defaultModelId ?? null,
    );
    const [makeId, setMakeId] = useState<number | null>(
        () =>
            catalog.makes.find((m) =>
                m.models.some((model) => model.id === defaultModelId),
            )?.id ?? null,
    );
    const { products, nextPage, loading, error, loadMore } = useProductSearch(
        { q: debounced, category_id: categoryId, model_id: modelId },
        refreshKey,
    );

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(query.trim()), 250);

        return () => clearTimeout(timer);
    }, [query]);

    const models = useMemo(
        () => catalog.makes.find((m) => m.id === makeId)?.models ?? [],
        [catalog.makes, makeId],
    );
    const term = debounced.toLowerCase();
    const services = catalog.services.filter(
        (s) =>
            !term ||
            s.name.toLowerCase().includes(term) ||
            s.code.toLowerCase().includes(term),
    );
    const packages = catalog.packages.filter(
        (p) => !term || p.name.toLowerCase().includes(term),
    );

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    ref={searchRef}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search name, SKU, part no., brand…  (F2)"
                    className="h-12 pr-10 pl-11 text-base"
                    aria-label="Search products"
                />
                {query && (
                    <button
                        type="button"
                        onClick={() => setQuery('')}
                        className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                        aria-label="Clear search"
                    >
                        <X className="size-5" />
                    </button>
                )}
            </div>

            <Tabs value={tab} onValueChange={setTab} className="min-h-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <TabsList>
                        <TabsTrigger value="parts">
                            <Boxes className="size-4" /> Parts
                        </TabsTrigger>
                        <TabsTrigger value="labor">
                            <Wrench className="size-4" /> Labor
                        </TabsTrigger>
                        <TabsTrigger value="packages">
                            <PackageOpen className="size-4" /> Packages
                        </TabsTrigger>
                    </TabsList>

                    {tab === 'parts' && (
                        <div className="ml-auto flex items-center gap-2">
                            <span className="font-display text-sm font-semibold text-muted-foreground">
                                Fits
                            </span>
                            <select
                                className={selectClass}
                                value={makeId ?? ''}
                                onChange={(e) => {
                                    setMakeId(
                                        e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    );
                                    setModelId(null);
                                }}
                                aria-label="Motorcycle make"
                            >
                                <option value="">Any make</option>
                                {catalog.makes.map((make) => (
                                    <option key={make.id} value={make.id}>
                                        {make.name}
                                    </option>
                                ))}
                            </select>
                            <select
                                className={selectClass}
                                value={modelId ?? ''}
                                disabled={!makeId}
                                onChange={(e) =>
                                    setModelId(
                                        e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    )
                                }
                                aria-label="Motorcycle model"
                            >
                                <option value="">Any model</option>
                                {models.map((model) => (
                                    <option key={model.id} value={model.id}>
                                        {model.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                </div>

                <TabsContent
                    value="parts"
                    className="flex min-h-0 flex-col gap-3"
                >
                    <div className="flex gap-2 overflow-x-auto pb-1">
                        <Chip
                            active={categoryId === null}
                            onClick={() => setCategoryId(null)}
                        >
                            All
                        </Chip>
                        {catalog.categories.map((category) => (
                            <Chip
                                key={category.id}
                                active={categoryId === category.id}
                                onClick={() => setCategoryId(category.id)}
                            >
                                <CategoryIcon
                                    icon={category.icon}
                                    className="size-4"
                                />
                                {category.name}
                            </Chip>
                        ))}
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto pr-1">
                        {error && (
                            <p className="p-4 text-destructive">{error}</p>
                        )}

                        {!error && !loading && products.length === 0 && (
                            <EmptyState icon={Boxes} title="No parts found">
                                Try another name or part no., or clear the
                                motorcycle / category filter.
                            </EmptyState>
                        )}

                        <div className="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-2">
                            {products.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                    free={
                                        product.available -
                                        (inCart?.(product.id) ?? 0)
                                    }
                                    allowNegative={
                                        catalog.settings.allowNegativeStock
                                    }
                                    disabled={disabled}
                                    onAdd={() => onAddPart(product)}
                                />
                            ))}
                            {loading &&
                                Array.from({
                                    length: products.length ? 4 : 12,
                                }).map((_, i) => (
                                    <Skeleton
                                        key={i}
                                        className="h-36 rounded-lg"
                                    />
                                ))}
                        </div>

                        {nextPage && !loading && (
                            <div className="py-3 text-center">
                                <Button variant="outline" onClick={loadMore}>
                                    Load more
                                </Button>
                            </div>
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="labor" className="min-h-0 overflow-y-auto">
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(220px,1fr))] gap-2">
                        {services.map((service) => (
                            <button
                                key={service.id}
                                type="button"
                                disabled={disabled}
                                onClick={() => onAddLabor(service)}
                                className="flex flex-col items-start gap-1 rounded-lg border bg-card p-3 text-left transition hover:border-primary hover:shadow-md disabled:opacity-50"
                            >
                                <span className="text-xs font-semibold text-muted-foreground">
                                    {service.code}
                                </span>
                                <span className="font-semibold">
                                    {service.name}
                                </span>
                                <span className="mt-auto flex w-full items-end justify-between">
                                    <span className="tabular font-display text-2xl font-bold text-primary">
                                        {peso(service.labor_fee)}
                                    </span>
                                    {service.est_minutes && (
                                        <span className="text-xs text-muted-foreground">
                                            ~{service.est_minutes} min
                                        </span>
                                    )}
                                </span>
                            </button>
                        ))}
                    </div>
                    {services.length === 0 && (
                        <EmptyState icon={Wrench} title="No labor matches" />
                    )}
                </TabsContent>

                <TabsContent
                    value="packages"
                    className="min-h-0 overflow-y-auto"
                >
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(260px,1fr))] gap-2">
                        {packages.map((pkg) => {
                            const discounted =
                                pkg.package_price !== null &&
                                pkg.package_price < pkg.regular_price;

                            return (
                                <button
                                    key={pkg.id}
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => onAddPackage(pkg)}
                                    className="flex flex-col gap-2 rounded-lg border bg-card p-3 text-left transition hover:border-primary hover:shadow-md disabled:opacity-50"
                                >
                                    <span className="font-display text-lg font-bold">
                                        {pkg.name}
                                    </span>
                                    <ul className="text-sm text-muted-foreground">
                                        {pkg.items.map((item, i) => (
                                            <li key={i}>
                                                {item.type === 'labor'
                                                    ? '🔧'
                                                    : '•'}{' '}
                                                {item.name}
                                                {item.qty !== 1 && (
                                                    <span className="tabular">
                                                        {' '}
                                                        × {qty(item.qty)}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                    <span className="mt-auto flex items-baseline gap-2">
                                        <span className="tabular font-display text-2xl font-bold text-primary">
                                            {peso(
                                                discounted
                                                    ? pkg.package_price
                                                    : pkg.regular_price,
                                            )}
                                        </span>
                                        {discounted && (
                                            <span className="tabular text-sm text-muted-foreground line-through">
                                                {peso(pkg.regular_price)}
                                            </span>
                                        )}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    {packages.length === 0 && (
                        <EmptyState icon={PackageOpen} title="No packages" />
                    )}
                </TabsContent>
            </Tabs>
        </div>
    );
}

function Chip({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors',
                active
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'bg-card hover:border-primary',
            )}
        >
            {children}
        </button>
    );
}

function ProductCard({
    product,
    free,
    allowNegative,
    disabled,
    onAdd,
}: {
    product: Product;
    free: number;
    allowNegative: boolean;
    disabled?: boolean;
    onAdd: () => void;
}) {
    const out = free <= 0;
    const low = !out && free <= product.reorder_level;
    const blocked = disabled || (out && !allowNegative);

    return (
        <button
            type="button"
            onClick={onAdd}
            disabled={blocked}
            title={out ? 'Out of stock' : `Add ${product.name}`}
            className={cn(
                'group flex h-full flex-col gap-1 rounded-lg border bg-card p-3 text-left transition',
                'hover:border-primary hover:shadow-md focus-visible:border-primary',
                blocked &&
                    'cursor-not-allowed opacity-50 hover:border-border hover:shadow-none',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <span className="line-clamp-2 leading-tight font-semibold">
                    {product.name}
                </span>
            </div>
            <span className="truncate text-xs text-muted-foreground">
                {[product.brand, product.part_number]
                    .filter(Boolean)
                    .join(' · ') || product.sku}
            </span>
            <span className="mt-auto flex items-end justify-between gap-2 pt-1">
                <span className="tabular font-display text-2xl leading-none font-bold text-primary">
                    {peso(product.srp)}
                </span>
                <span
                    className={cn(
                        'tabular rounded px-1.5 py-0.5 text-xs font-bold',
                        out
                            ? 'bg-destructive text-white'
                            : low
                              ? 'bg-hivis text-asphalt'
                              : 'bg-paid/15 text-paid',
                    )}
                >
                    {out ? 'Out' : `${qty(free)} ${product.unit ?? ''}`}
                </span>
            </span>
            {product.reserved > 0 && (
                <span className="tabular text-[11px] text-muted-foreground">
                    On hand {qty(product.on_hand)} · reserved{' '}
                    {qty(product.reserved)}
                </span>
            )}
        </button>
    );
}
