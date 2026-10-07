<?php

namespace App\Support;

/**
 * Money helpers. Amounts are handled as strings/floats rounded to centavos at every
 * step that is persisted, so stored decimals and displayed values always agree.
 */
final class Money
{
    /**
     * ₱1,234.00 (negative: -₱1,234.00)
     */
    public static function format(float|int|string|null $amount): string
    {
        $value = (float) ($amount ?? 0);
        $formatted = '₱'.number_format(abs($value), 2);

        return $value < 0 ? '-'.$formatted : $formatted;
    }

    public static function round(float|int|string|null $amount): float
    {
        return round((float) ($amount ?? 0), 2);
    }
}
