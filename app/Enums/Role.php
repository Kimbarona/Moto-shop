<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Fixed roles from the access matrix. Fine-grained rules live in Policies and Gates
 * (AppServiceProvider); these helpers only group the roles.
 */
enum Role: string implements HasColor, HasLabel
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Cashier = 'cashier';
    case Mechanic = 'mechanic';
    case InventoryClerk = 'inventory_clerk';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin / Manager',
            self::Cashier => 'Cashier',
            self::Mechanic => 'Mechanic',
            self::InventoryClerk => 'Inventory Clerk',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Admin => 'primary',
            self::Cashier => 'success',
            self::Mechanic => 'info',
            self::InventoryClerk => 'warning',
        };
    }

    /**
     * Super admins and admins (managers) share most back-office permissions.
     */
    public function isAdmin(): bool
    {
        return $this === self::SuperAdmin || $this === self::Admin;
    }

    /**
     * Roles that may sign in to the Filament back-office.
     */
    public function canAccessBackOffice(): bool
    {
        return $this->isAdmin() || $this === self::InventoryClerk;
    }

    /**
     * Where the user lands after logging in.
     */
    public function homePath(): string
    {
        return match ($this) {
            self::SuperAdmin, self::Admin, self::InventoryClerk => '/admin',
            self::Cashier => '/pos',
            self::Mechanic => '/service-board',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->getLabel()])->all();
    }
}
