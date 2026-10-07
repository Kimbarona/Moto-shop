<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CommissionType: string implements HasColor, HasLabel
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percent => 'Percent of labor',
            self::Fixed => 'Fixed amount',
            self::None => 'No commission',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Percent => 'info',
            self::Fixed => 'warning',
            self::None => 'gray',
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
