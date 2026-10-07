<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReturnResolution: string implements HasColor, HasLabel
{
    case Refund = 'refund';
    case Exchange = 'exchange';

    public function getLabel(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::Exchange => 'Exchange',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Refund => 'purple',
            self::Exchange => 'info',
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
