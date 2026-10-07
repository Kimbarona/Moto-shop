<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class TaxSettings extends Settings
{
    public bool $vat_registered;

    public float $vat_rate;

    public bool $prices_vat_inclusive;

    public static function group(): string
    {
        return 'tax';
    }
}
