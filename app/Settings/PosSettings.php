<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PosSettings extends Settings
{
    public float $cashier_max_discount_percent;

    public bool $require_reference_for_non_cash;

    public ?int $default_payment_method_id;

    public bool $allow_price_override;

    public bool $allow_walk_in_without_customer;

    public static function group(): string
    {
        return 'pos';
    }
}
