import {
    ArrowDownUp,
    BatteryCharging,
    Bike,
    CircleDot,
    Cog,
    Disc3,
    Droplet,
    Filter,
    Link2,
    Package,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

const icons: Record<string, LucideIcon> = {
    engine: Cog,
    brake: Disc3,
    battery: BatteryCharging,
    tire: CircleDot,
    oil: Droplet,
    chain: Link2,
    suspension: ArrowDownUp,
    body: Bike,
    filter: Filter,
};

export function CategoryIcon({
    icon,
    className,
}: {
    icon: string | null;
    className?: string;
}) {
    const Icon = (icon && icons[icon]) || Package;

    return <Icon className={className} strokeWidth={1.75} />;
}
