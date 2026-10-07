<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DefectiveStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case ReturnedToSupplier = 'returned_to_supplier';
    case WrittenOff = 'written_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::ReturnedToSupplier => 'Returned to Supplier',
            self::WrittenOff => 'Written Off',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::ReturnedToSupplier => 'info',
            self::WrittenOff => 'gray',
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
