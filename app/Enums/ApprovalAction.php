<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Restricted actions a cashier needs a Manager PIN for.
 */
enum ApprovalAction: string implements HasColor, HasLabel
{
    case Discount = 'discount';
    case PriceOverride = 'price_override';
    case VoidOrder = 'void_order';
    case Return = 'return';

    public function getLabel(): string
    {
        return match ($this) {
            self::Discount => 'Discount above limit',
            self::PriceOverride => 'Price override',
            self::VoidOrder => 'Void order',
            self::Return => 'Return / refund',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Discount => 'warning',
            self::PriceOverride => 'warning',
            self::VoidOrder => 'danger',
            self::Return => 'purple',
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
