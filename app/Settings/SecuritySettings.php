<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SecuritySettings extends Settings
{
    public int $session_timeout_minutes;

    public int $password_min_length;

    public int $max_pin_attempts;

    public static function group(): string
    {
        return 'security';
    }
}
