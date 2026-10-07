import { Boxes, ClipboardList, ShoppingCart, Warehouse } from 'lucide-react';
import { pos, serviceBoard, stockLookup } from '@/routes';
import type { Auth, NavItem } from '@/types';

/**
 * Front-of-house navigation filtered by what the signed-in role may use.
 * The server enforces the same rules; this only hides links.
 */
export function mainNavItems(can: Auth['can']): NavItem[] {
    const items: NavItem[] = [];

    if (can.usePos) {
        items.push({ title: 'POS', href: pos(), icon: ShoppingCart });
    }

    if (can.useServiceBoard) {
        items.push({
            title: 'Service Board',
            href: serviceBoard(),
            icon: ClipboardList,
        });
    }

    items.push({ title: 'Stock Lookup', href: stockLookup(), icon: Boxes });

    if (can.accessAdmin) {
        items.push({ title: 'Back-office', href: '/admin', icon: Warehouse });
    }

    return items;
}
