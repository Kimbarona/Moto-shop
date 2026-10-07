<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The SKU prefix lives in the sequences table (key "sku").
 */
class InventorySettings extends Settings
{
    public bool $allow_negative_stock;

    public float $default_reorder_level;

    public int $dead_stock_days;

    public static function group(): string
    {
        return 'inventory';
    }
}
