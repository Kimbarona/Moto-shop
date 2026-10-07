<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Walk-in counter sale or a service (job) order.
 */
enum OrderType: string implements HasColor, HasLabel
{
    case WalkIn = 'walk_in';
    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Service => 'Service',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WalkIn => 'gray',
            self::Service => 'info',
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
