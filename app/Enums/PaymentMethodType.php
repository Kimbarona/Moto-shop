<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Labels for tracking only. There is no payment gateway integration.
 */
enum PaymentMethodType: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case Ewallet = 'ewallet';
    case Bank = 'bank';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Ewallet => 'E-Wallet',
            self::Bank => 'Bank',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cash => 'success',
            self::Ewallet => 'info',
            self::Bank => 'warning',
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
