<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where a returned part goes.
 */
enum Disposition: string implements HasColor, HasLabel
{
    case BackToStock = 'back_to_stock';
    case Defective = 'defective';

    public function getLabel(): string
    {
        return match ($this) {
            self::BackToStock => 'Back to Stock',
            self::Defective => 'Defective',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BackToStock => 'success',
            self::Defective => 'danger',
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
