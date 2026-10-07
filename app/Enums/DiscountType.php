<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasColor, HasLabel
{
    case Amount = 'amount';
    case Percent = 'percent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Amount => 'Peso (₱)',
            self::Percent => 'Percent (%)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Amount => 'gray',
            self::Percent => 'gray',
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
