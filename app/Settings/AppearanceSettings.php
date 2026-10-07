<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppearanceSettings extends Settings
{
    public string $pos_theme;

    public static function group(): string
    {
        return 'appearance';
    }
}
