import { router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Ban,
    Bike,
    ClipboardPlus,
    Gauge,
    Pencil,
    Printer,
    Save,
    Trash2,
    UserPlus,
    Wallet,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { CartLines, CartTotals } from '@/components/pos/cart-panel';
import { CustomerPicker } from '@/components/pos/customer-picker';
import type { CustomerSelection } from '@/components/pos/customer-picker';
import { PayDialog } from '@/components/pos/pay-dialog';
import { ProductFinder } from '@/components/pos/product-finder';
import { ServiceOrderDialog } from '@/components/pos/service-order-dialog';
import { StatusBadge } from '@/components/pos/status-badge';
import { VoidDialog } from '@/components/pos/void-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocalCart, useOrderCart } from '@/hooks/use-cart';
import type { Cart } from '@/hooks/use-cart';
import { usePin } from '@/hooks/use-pin';
import PosLayout from '@/layouts/pos-layout';
import { api, openPrint, toApiError, urls } from '@/lib/api';
import { age, qty } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Catalog, Order, ServiceStatus } from '@/types/pos';

type Props = Catalog & { mode: 'pos' | 'job'; order: Order | null };

export default function PosPage(props: Props) {
    const title = props.order ? `${props.order.order_no}` : 'POS';

    return (
        <PosLayout
            title={title}
            openOrdersCount={props.openOrdersCount}
            fullHeight
        >
            {props.order ? (
                <OrderPos key={props.order.id} {...props} order={props.order} />
            ) : (
                <LocalPos {...props} />
            )}
        </PosLayout>
    );
}

/** F2 search · F4 new sale · F9 pay (Esc is handled by dialogs). */
function useHotkeys(handlers: {
    search?: () => void;
    newSale?: () => void;
    pay?: () => void;
}) {
    const ref = useRef(handlers);

    useEffect(() => {
        ref.current = handlers;
    });

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const map: Record<string, (() => void) | undefined> = {
                F2: ref.current.search,
                F4: ref.current.newSale,
                F9: ref.current.pay,
            };
            const handler = map[event.key];

            if (handler) {
                event.preventDefault();
                handler();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);
}

function Workspace({
    catalog,
    cart,
    header,
    footer,
    canEdit,
    canDiscount,
    showMechanics,
    defaultModelId,
    refreshKey,
    searchRef,
}: {
    catalog: Catalog;
    cart: Cart;
    header: ReactNode;
    footer: ReactNode;
    canEdit: boolean;
    canDiscount: boolean;
    showMechanics?: boolean;
    defaultModelId?: number | null;
    refreshKey: unknown;
    searchRef: React.RefObject<HTMLInputElement | null>;
}) {
    const inCart = (productId: number) =>
        cart.kind === 'local'
            ? cart.lines
                  .filter((l) => l.product_id === productId)
                  .reduce((sum, l) => sum + l.qty, 0)
            : 0;

    return (
        <div className="grid min-h-0 flex-1 grid-cols-1 gap-3 p-3 lg:grid-cols-[minmax(0,1fr)_440px] xl:grid-cols-[minmax(0,1fr)_480px]">
            <section className="flex min-h-[50svh] flex-col rounded-xl border bg-card/40 p-3 lg:min-h-0">
                <ProductFinder
                    catalog={catalog}
                    onAddPart={(p) => cart.addPart(p)}
                    onAddLabor={(s) => cart.addLabor(s)}
                    onAddPackage={(p) => cart.addPackage(p)}
                    inCart={inCart}
                    disabled={!canEdit || cart.busy}
                    defaultModelId={defaultModelId}
                    refreshKey={refreshKey}
                    searchRef={searchRef}
                />
            </section>

            <aside className="flex min-h-0 flex-col overflow-hidden rounded-xl border bg-card shadow-sm">
                <div className="border-b bg-muted/40 p-3">{header}</div>
                <div className="min-h-0 flex-1 overflow-y-auto px-3">
                    <CartLines
                        cart={cart}
                        catalog={catalog}
                        canEdit={canEdit}
                        canDiscount={canDiscount}
                        showMechanics={showMechanics}
                    />
                </div>
                <div className="space-y-3 border-t bg-muted/30 p-3">
                    <CartTotals
                        cart={cart}
                        catalog={catalog}
                        canDiscount={canDiscount}
                    />
                    {footer}
                </div>
            </aside>
        </div>
    );
}

/* ------------------------------------------------------------------ walk-in cart */

function LocalPos(props: Props) {
    const { auth } = usePage().props;
    const cart = useLocalCart(auth.user.id, props.settings);
    const { withPin } = usePin();
    const searchRef = useRef<HTMLInputElement>(null);
    const [customer, setCustomer] = useState<CustomerSelection>({
        customer: null,
        motorcycle: null,
    });
    const [customerOpen, setCustomerOpen] = useState(false);
    const [payOpen, setPayOpen] = useState(false);
    const [serviceOpen, setServiceOpen] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);
    const [saving, setSaving] = useState(false);

    const empty = cart.lines.length === 0;
    const needsCustomer =
        !props.settings.allowWalkInWithoutCustomer && !customer.customer;

    const newSale = () => {
        if (
            !empty &&
            !window.confirm('Clear the current cart and start a new sale?')
        ) {
            return;
        }

        cart.clear();
        setCustomer({ customer: null, motorcycle: null });
        searchRef.current?.focus();
    };

    useHotkeys({
        search: () => searchRef.current?.focus(),
        newSale,
        pay: () => !empty && auth.can.takePayment && setPayOpen(true),
    });

    useEffect(() => searchRef.current?.focus(), []);

    const orderAttributes = () => ({
        customer_id: customer.customer?.id ?? null,
        customer_motorcycle_id: customer.motorcycle?.id ?? null,
    });

    const pay = async (payment: Parameters<typeof api.pay>[1]) => {
        try {
            const order = await withPin((pin) =>
                api.checkout({
                    lines: cart.payload(),
                    ...orderAttributes(),
                    discount_type:
                        cart.orderDiscount.value > 0
                            ? cart.orderDiscount.type
                            : null,
                    discount_value: cart.orderDiscount.value || null,
                    ...payment,
                    pin,
                }),
            );

            if (!order) {
                return false;
            }

            toast.success(`Paid · slip ${order.slip_no}`, {
                description: order.payments[0]?.change_due
                    ? `Change ${order.payments[0].change_due.toFixed(2)}`
                    : undefined,
            });
            openPrint(urls.slip(order.id));
            cart.clear();
            setCustomer({ customer: null, motorcycle: null });
            setRefreshKey((k) => k + 1);
            searchRef.current?.focus();

            return true;
        } catch (e) {
            toast.error(toApiError(e).first());

            return false;
        }
    };

    const saveAsPending = async () => {
        setSaving(true);

        try {
            const order = await withPin(async (pin) => {
                const created = await api.createOrder({
                    type: 'walk_in',
                    ...orderAttributes(),
                    lines: cart.payload(),
                    pin,
                });

                return cart.orderDiscount.value > 0
                    ? api.setDiscount(created.id, {
                          discount_type: cart.orderDiscount.type,
                          discount_value: cart.orderDiscount.value,
                          pin,
                      })
                    : created;
            });

            if (order) {
                toast.success(`Saved as Pending · ${order.order_no}`, {
                    description: 'Find it under Open Orders.',
                });
                cart.clear();
                setCustomer({ customer: null, motorcycle: null });
                router.reload({ only: ['openOrdersCount'] });
            }
        } catch (e) {
            toast.error(toApiError(e).first());
        } finally {
            setSaving(false);
        }
    };

    const header = (
        <div className="flex items-center gap-2">
            <div className="min-w-0 flex-1">
                <p className="font-display text-2xl leading-none font-extrabold">
                    Walk-in sale
                </p>
                <p className="mt-1 truncate text-sm text-muted-foreground">
                    {customer.customer ? (
                        <>
                            {customer.customer.name}
                            {customer.motorcycle &&
                                ` · ${customer.motorcycle.plate_no ?? customer.motorcycle.model ?? ''}`}
                        </>
                    ) : (
                        'No customer'
                    )}
                </p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setCustomerOpen(true)}
            >
                <UserPlus /> {customer.customer ? 'Change' : 'Customer'}
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setServiceOpen(true)}
            >
                <ClipboardPlus /> Service order
            </Button>
        </div>
    );

    const footer = (
        <div className="grid grid-cols-[auto_1fr_1.4fr] gap-2">
            <Button
                type="button"
                variant="outline"
                size="xl"
                onClick={newSale}
                disabled={empty}
                aria-label="Clear cart (F4)"
                title="Clear (F4)"
            >
                <Trash2 />
            </Button>
            <Button
                type="button"
                variant="secondary"
                size="xl"
                onClick={saveAsPending}
                disabled={empty || saving}
            >
                <Save /> Save as Pending
            </Button>
            <Button
                type="button"
                variant="racing"
                size="xl"
                className="text-2xl"
                disabled={empty || !auth.can.takePayment || needsCustomer}
                onClick={() => setPayOpen(true)}
                title={needsCustomer ? 'Pick a customer first' : 'Pay (F9)'}
            >
                <Wallet className="size-6" /> PAY{' '}
                <kbd className="text-xs opacity-70">F9</kbd>
            </Button>
        </div>
    );

    return (
        <>
            <Workspace
                catalog={props}
                cart={cart}
                header={header}
                footer={footer}
                canEdit
                canDiscount={auth.can.takePayment}
                refreshKey={refreshKey}
                searchRef={searchRef}
                defaultModelId={customer.motorcycle?.model_id ?? null}
            />

            <PayDialog
                open={payOpen}
                onOpenChange={setPayOpen}
                amountDue={cart.totals.amount_due}
                methods={props.paymentMethods}
                defaultMethodId={props.settings.defaultPaymentMethodId}
                onPay={pay}
            />

            <Dialog open={customerOpen} onOpenChange={setCustomerOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle className="font-display text-2xl">
                            Customer
                        </DialogTitle>
                    </DialogHeader>
                    <CustomerPicker
                        makes={props.makes}
                        value={customer}
                        onChange={setCustomer}
                    />
                    <DialogFooter>
                        <Button
                            type="button"
                            onClick={() => setCustomerOpen(false)}
                        >
                            Done
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ServiceOrderDialog
                open={serviceOpen}
                onOpenChange={setServiceOpen}
                catalog={props}
                onSaved={(order) => {
                    toast.success(`Job ${order.order_no} opened`);

                    if (props.settings.showJobTicketOnCreate) {
                        openPrint(urls.jobTicket(order.id));
                    }

                    router.visit(urls.pos(order.id));
                }}
            />
        </>
    );
}

/* ------------------------------------------------------------- pending order */

const statusSteps: { value: ServiceStatus; label: string }[] = [
    { value: 'waiting', label: 'Waiting' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
];

function OrderPos(props: Props & { order: Order }) {
    const { auth } = usePage().props;
    const cart = useOrderCart(props.order);
    const order = cart.order!;
    const searchRef = useRef<HTMLInputElement>(null);
    const [payOpen, setPayOpen] = useState(false);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [voidOpen, setVoidOpen] = useState(false);
    // Re-fetch availability whenever the lines change.
    const refreshKey = order.items;
    const isJob = props.mode === 'job';

    const back = () =>
        router.visit(
            isJob || !auth.can.usePos ? urls.serviceBoard : urls.openOrders,
        );

    useHotkeys({
        search: () => searchRef.current?.focus(),
        newSale: () => auth.can.usePos && router.visit(urls.pos()),
        pay: () => order.can.pay && order.items.length > 0 && setPayOpen(true),
    });

    const setStatus = async (status: ServiceStatus) => {
        try {
            cart.replaceOrder(await api.setServiceStatus(order.id, status));
            toast.success(
                status === 'done' ? 'Ready for payment' : 'Status updated',
            );
        } catch (e) {
            toast.error(toApiError(e).first());
        }
    };

    const pay = async (payment: Parameters<typeof api.pay>[1]) => {
        try {
            const paid = await api.pay(order.id, payment);
            toast.success(`Paid · slip ${paid.slip_no}`);
            openPrint(urls.slip(paid.id));
            router.visit(urls.pos());

            return true;
        } catch (e) {
            toast.error(toApiError(e).first());

            return false;
        }
    };

    const header = (
        <div className="space-y-2">
            <div className="flex items-start gap-2">
                <button
                    type="button"
                    onClick={back}
                    className="mt-1 text-muted-foreground hover:text-foreground"
                    aria-label="Back"
                >
                    <ArrowLeft className="size-5" />
                </button>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-display text-2xl leading-none font-extrabold">
                            {order.order_no}
                        </p>
                        <StatusBadge status={order.status} />
                        {order.service_status && (
                            <StatusBadge status={order.service_status} />
                        )}
                    </div>
                    <p className="mt-1 truncate text-sm">
                        <span className="font-semibold">
                            {order.customer?.name ?? 'Walk-in'}
                        </span>
                        {order.motorcycle && (
                            <span className="text-muted-foreground">
                                {' '}
                                · <Bike className="inline size-3.5" />{' '}
                                <span className="font-display text-base font-bold text-foreground">
                                    {order.motorcycle.plate_no ?? 'No plate'}
                                </span>{' '}
                                {order.motorcycle.model}
                            </span>
                        )}
                    </p>
                    <p className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                        {order.odometer_in !== null && (
                            <span>
                                <Gauge className="inline size-3" />{' '}
                                {qty(order.odometer_in)} km
                            </span>
                        )}
                        {order.mechanics.length > 0 && (
                            <span>
                                <Wrench className="inline size-3" />{' '}
                                {order.mechanics.map((m) => m.name).join(', ')}
                            </span>
                        )}
                        <span>in shop {age(order.created_at)}</span>
                    </p>
                    {order.complaint && (
                        <p className="mt-1 line-clamp-2 text-sm italic">
                            “{order.complaint}”
                        </p>
                    )}
                </div>
                {order.can.editItems && order.type === 'service' && (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={() => setDetailsOpen(true)}
                        aria-label="Edit job details"
                    >
                        <Pencil />
                    </Button>
                )}
            </div>

            {order.type === 'service' && order.can.serviceStatus && (
                <div className="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1">
                    {statusSteps.map((step) => (
                        <button
                            key={step.value}
                            type="button"
                            onClick={() =>
                                order.service_status !== step.value &&
                                setStatus(step.value)
                            }
                            className={cn(
                                'h-9 rounded-md font-display text-sm font-bold tracking-wide transition',
                                order.service_status === step.value
                                    ? step.value === 'done'
                                        ? 'bg-paid text-white'
                                        : 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-background',
                            )}
                        >
                            {step.label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );

    const footer = (
        <div className="flex flex-wrap gap-2">
            {order.type === 'service' && (
                <Button
                    type="button"
                    variant="outline"
                    size="xl"
                    onClick={() => openPrint(urls.jobTicket(order.id, true))}
                    title="Print job ticket"
                >
                    <Printer />
                </Button>
            )}
            {order.can.void && !isJob && (
                <Button
                    type="button"
                    variant="outline"
                    size="xl"
                    onClick={() => setVoidOpen(true)}
                    className="text-destructive"
                    title="Void"
                >
                    <Ban />
                </Button>
            )}
            {isJob || !order.can.pay ? (
                <Button
                    type="button"
                    variant="secondary"
                    size="xl"
                    className="flex-1"
                    onClick={back}
                >
                    <ArrowLeft /> Back to board
                </Button>
            ) : (
                <Button
                    type="button"
                    variant="racing"
                    size="xl"
                    className="flex-1 text-xl"
                    disabled={order.items.length === 0 || cart.busy}
                    onClick={() => setPayOpen(true)}
                >
                    <Wallet className="size-6" /> Finalize & Pay{' '}
                    <kbd className="text-xs opacity-70">F9</kbd>
                </Button>
            )}
        </div>
    );

    return (
        <>
            <Workspace
                catalog={props}
                cart={cart}
                header={header}
                footer={footer}
                canEdit={order.can.editItems}
                canDiscount={order.can.discount && !isJob}
                showMechanics={order.type === 'service'}
                defaultModelId={order.motorcycle?.model_id ?? null}
                refreshKey={refreshKey}
                searchRef={searchRef}
            />

            <PayDialog
                open={payOpen}
                onOpenChange={setPayOpen}
                title={`Finalize ${order.order_no}`}
                amountDue={order.amount_due}
                methods={props.paymentMethods}
                defaultMethodId={props.settings.defaultPaymentMethodId}
                onPay={pay}
            />

            <ServiceOrderDialog
                open={detailsOpen}
                onOpenChange={setDetailsOpen}
                catalog={props}
                order={order}
                onSaved={cart.replaceOrder}
            />

            <VoidDialog
                order={order}
                open={voidOpen}
                onOpenChange={setVoidOpen}
                onVoided={() => router.visit(urls.openOrders)}
            />
        </>
    );
}
