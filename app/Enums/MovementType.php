<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Every change to sellable stock is one of these, recorded in stock_movements.
 */
enum MovementType: string implements HasColor, HasLabel
{
    case StockIn = 'stock_in';
    case Sale = 'sale';
    case ReturnToStock = 'return_to_stock';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case CountVariance = 'count_variance';
    case VoidReversal = 'void_reversal';
    case DefectiveOut = 'defective_out';
    case SupplierReturn = 'supplier_return';

    public function getLabel(): string
    {
        return match ($this) {
            self::StockIn => 'Stock In',
            self::Sale => 'Sale',
            self::ReturnToStock => 'Return to Stock',
            self::AdjustmentIn => 'Adjustment In',
            self::AdjustmentOut => 'Adjustment Out',
            self::CountVariance => 'Count Variance',
            self::VoidReversal => 'Void Reversal',
            self::DefectiveOut => 'Defective Out',
            self::SupplierReturn => 'Supplier Return',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::StockIn => 'success',
            self::Sale => 'info',
            self::ReturnToStock => 'success',
            self::AdjustmentIn => 'success',
            self::AdjustmentOut => 'danger',
            self::CountVariance => 'warning',
            self::VoidReversal => 'gray',
            self::DefectiveOut => 'danger',
            self::SupplierReturn => 'danger',
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
