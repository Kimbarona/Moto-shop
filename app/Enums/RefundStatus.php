<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RefundStatus: string implements HasColor, HasLabel
{
    case None = 'none';
    case Partial = 'partial';
    case Full = 'full';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Partial => 'Partially Refunded',
            self::Full => 'Refunded',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Partial => 'purple',
            self::Full => 'purple',
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
