import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Boxes,
    ClipboardList,
    LayoutDashboard,
    ListOrdered,
    LogOut,
    Moon,
    ReceiptText,
    Settings,
    ShoppingCart,
    Sun,
    Undo2,
    UserRound,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';
import { Toaster, toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import { PinProvider } from '@/hooks/use-pin';
import { urls } from '@/lib/api';
import { cn } from '@/lib/utils';

type NavItem = {
    label: string;
    href: string;
    icon: typeof ShoppingCart;
    show: boolean;
    badge?: number;
    shortcut?: string;
    active?: boolean;
};

const CHOSEN_KEY = 'appearance-chosen';

/**
 * Front-of-house shell: diamond-plate top bar, role-aware navigation, toasts and the
 * manager-PIN dialog. Dark by default (App Settings → Appearance) until the user picks.
 */
export default function PosLayout({
    title,
    children,
    openOrdersCount,
    actions,
    fullHeight = false,
}: {
    title: string;
    children: ReactNode;
    openOrdersCount?: number;
    actions?: ReactNode;
    fullHeight?: boolean;
}) {
    const page = usePage();
    const { auth, shop, flash } = page.props;
    const { resolvedAppearance, updateAppearance } = useAppearance();
    const path = page.url.split('?')[0];

    useEffect(() => {
        try {
            if (!localStorage.getItem(CHOSEN_KEY) && shop.posTheme) {
                updateAppearance(shop.posTheme);
            }
        } catch {
            // Storage unavailable: keep the current theme.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [shop.posTheme]);

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }

        if (flash?.warning) {
            toast.warning(flash.warning);
        }
    }, [flash]);

    const toggleTheme = () => {
        try {
            localStorage.setItem(CHOSEN_KEY, '1');
        } catch {
            // ignore
        }

        updateAppearance(resolvedAppearance === 'dark' ? 'light' : 'dark');
    };

    const nav: NavItem[] = [
        {
            label: 'New Sale',
            href: urls.pos(),
            icon: ShoppingCart,
            show: auth.can.usePos,
            shortcut: 'F4',
            active: path === '/pos',
        },
        {
            label: 'Open Orders',
            href: urls.openOrders,
            icon: ListOrdered,
            show: auth.can.useServiceBoard,
            badge: openOrdersCount,
            active: path === '/orders/open',
        },
        {
            label: 'Service Board',
            href: urls.serviceBoard,
            icon: ClipboardList,
            show: auth.can.useServiceBoard,
            active: path === '/service-board' || path.startsWith('/jobs'),
        },
        {
            label: 'Returns',
            href: urls.returns,
            icon: Undo2,
            show: auth.can.takePayment,
            active: path.startsWith('/returns'),
        },
        {
            label: 'Stock',
            href: urls.stockLookup,
            icon: Boxes,
            show: true,
            active: path === '/stock-lookup',
        },
        {
            label: 'My Summary',
            href: urls.mySummary,
            icon: ReceiptText,
            show: true,
            active: path === '/my-summary',
        },
    ];

    return (
        <PinProvider>
            <Head title={title} />
            <div
                className={cn(
                    'flex min-h-svh flex-col bg-background',
                    fullHeight && 'h-svh overflow-hidden',
                )}
            >
                <header className="diamond-plate sticky top-0 z-30 border-b border-steel text-white shadow-md">
                    <div className="flex h-14 items-center gap-2 px-3">
                        <Link
                            href="/"
                            className="mr-2 flex shrink-0 items-center gap-2"
                        >
                            {shop.logo ? (
                                <img
                                    src={shop.logo}
                                    alt=""
                                    className="h-8 w-auto rounded bg-white/90 p-0.5"
                                />
                            ) : (
                                <span className="grid size-8 place-items-center rounded bg-racing text-asphalt">
                                    <Wrench className="size-5" />
                                </span>
                            )}
                            <span className="hidden font-display text-xl font-extrabold tracking-wide lg:inline">
                                {shop.name}
                            </span>
                        </Link>

                        <nav className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
                            {nav
                                .filter((item) => item.show)
                                .map((item) => (
                                    <Link
                                        key={item.label}
                                        href={item.href}
                                        className={cn(
                                            'relative flex h-10 shrink-0 items-center gap-2 rounded-md px-3 font-display text-[15px] font-semibold tracking-wide text-zinc-300 transition-colors hover:bg-gunmetal hover:text-white',
                                            item.active &&
                                                'racing-stripe bg-gunmetal text-racing shadow-[inset_0_-3px_0_var(--color-racing)]',
                                        )}
                                    >
                                        <item.icon className="size-4" />
                                        <span className="hidden md:inline">
                                            {item.label}
                                        </span>
                                        {item.shortcut && (
                                            <kbd className="hidden rounded bg-white/10 px-1 text-[10px] text-zinc-400 xl:inline">
                                                {item.shortcut}
                                            </kbd>
                                        )}
                                        {item.badge !== undefined &&
                                            item.badge > 0 && (
                                                <Badge className="tabular h-5 min-w-5 rounded-full bg-hivis px-1.5 text-asphalt">
                                                    {item.badge}
                                                </Badge>
                                            )}
                                    </Link>
                                ))}
                        </nav>

                        {actions}

                        <button
                            type="button"
                            onClick={toggleTheme}
                            className="grid size-10 place-items-center rounded-md text-zinc-300 hover:bg-gunmetal hover:text-white"
                            aria-label="Toggle light / dark"
                        >
                            {resolvedAppearance === 'dark' ? (
                                <Sun className="size-5" />
                            ) : (
                                <Moon className="size-5" />
                            )}
                        </button>

                        <DropdownMenu>
                            <DropdownMenuTrigger className="flex h-10 items-center gap-2 rounded-md px-2 text-left hover:bg-gunmetal">
                                <span className="grid size-8 place-items-center rounded-full bg-gunmetal">
                                    <UserRound className="size-4" />
                                </span>
                                <span className="hidden leading-tight sm:block">
                                    <span className="block text-sm font-semibold">
                                        {auth.user.name}
                                    </span>
                                    <span className="block text-[11px] text-zinc-400">
                                        {auth.user.role_label}
                                    </span>
                                </span>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel>
                                    {auth.user.email}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {auth.can.accessAdmin && (
                                    <DropdownMenuItem asChild>
                                        <a href="/admin">
                                            <LayoutDashboard className="mr-2" />{' '}
                                            Back-office
                                        </a>
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem asChild>
                                    <Link href="/settings/profile">
                                        <Settings className="mr-2" /> My account
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => router.post('/logout')}
                                >
                                    <LogOut className="mr-2" /> Log out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>

                <main
                    className={cn(
                        'flex flex-1 flex-col',
                        fullHeight && 'min-h-0',
                    )}
                >
                    {children}
                </main>
            </div>
            <Toaster
                position="top-center"
                richColors
                closeButton
                theme={resolvedAppearance}
            />
        </PinProvider>
    );
}
