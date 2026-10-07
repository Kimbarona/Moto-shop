<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Shop profile shown on slips, the sidebar and the login page.
 */
class ShopSettings extends Settings
{
    public string $shop_name;

    public ?string $logo;

    public ?string $address;

    public ?string $mobile;

    public ?string $email;

    public ?string $tin;

    public ?string $business_hours;

    public static function group(): string
    {
        return 'shop';
    }
}
