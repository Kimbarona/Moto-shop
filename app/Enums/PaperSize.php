<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaperSize: string implements HasColor, HasLabel
{
    case Thermal58 = '58mm';
    case Thermal80 = '80mm';
    case A4 = 'a4';
    case HalfLetter = 'half_letter';

    public function getLabel(): string
    {
        return match ($this) {
            self::Thermal58 => '58 mm thermal',
            self::Thermal80 => '80 mm thermal',
            self::A4 => 'A4',
            self::HalfLetter => 'Half-letter',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Thermal58 => 'gray',
            self::Thermal80 => 'gray',
            self::A4 => 'gray',
            self::HalfLetter => 'gray',
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
