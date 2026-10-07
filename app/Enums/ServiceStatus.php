<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Service board columns.
 */
enum ServiceStatus: string implements HasColor, HasLabel
{
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::InProgress => 'In Progress',
            self::Done => 'Ready for Payment',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Waiting => 'gray',
            self::InProgress => 'info',
            self::Done => 'success',
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
