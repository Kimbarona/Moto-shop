<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdjustmentReason: string implements HasColor, HasLabel
{
    case Damaged = 'damaged';
    case Lost = 'lost';
    case Found = 'found';
    case InternalUse = 'internal_use';
    case Correction = 'correction';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Damaged => 'Damaged',
            self::Lost => 'Lost',
            self::Found => 'Found',
            self::InternalUse => 'Internal Use',
            self::Correction => 'Correction',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Damaged => 'danger',
            self::Lost => 'danger',
            self::Found => 'success',
            self::InternalUse => 'warning',
            self::Correction => 'info',
            self::Other => 'gray',
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
