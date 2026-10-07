<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LineType: string implements HasColor, HasLabel
{
    case Part = 'part';
    case Labor = 'labor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Part => 'Part',
            self::Labor => 'Labor',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Part => 'gray',
            self::Labor => 'info',
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
