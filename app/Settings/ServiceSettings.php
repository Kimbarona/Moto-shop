<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ServiceSettings extends Settings
{
    public float $default_commission_percent;

    public int $pms_interval_km;

    public int $pms_interval_months;

    public bool $show_job_ticket_on_create;

    public static function group(): string
    {
        return 'service';
    }
}
