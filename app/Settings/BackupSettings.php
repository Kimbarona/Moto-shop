<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BackupSettings extends Settings
{
    public ?string $last_backup_at;

    public static function group(): string
    {
        return 'backup';
    }
}
