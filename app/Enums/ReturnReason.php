<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReturnReason: string implements HasColor, HasLabel
{
    case WrongPart = 'wrong_part';
    case Defective = 'defective';
    case NotNeeded = 'not_needed';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WrongPart => 'Wrong Part',
            self::Defective => 'Defective',
            self::NotNeeded => 'Not Needed',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::WrongPart => 'warning',
            self::Defective => 'danger',
            self::NotNeeded => 'gray',
            self::Other => 'gray',
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
