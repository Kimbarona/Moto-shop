<?php

namespace App\Reports;

use Carbon\CarbonImmutable;

/**
 * A report column. Types drive formatting on screen, in Excel and in PDF:
 * text, money (₱1,234.00), qty (1,234.50), int, percent, date, datetime.
 */
final class Column
{
    public bool $total = false;

    public bool $costOnly = false;

    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'text');
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, 'money');
    }

    public static function qty(string $key, string $label): self
    {
        return new self($key, $label, 'qty');
    }

    public static function int(string $key, string $label): self
    {
        return new self($key, $label, 'int');
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'percent');
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, 'date');
    }

    public static function datetime(string $key, string $label): self
    {
        return new self($key, $label, 'datetime');
    }

    /**
     * Sum this column in the totals row.
     */
    public function total(bool $total = true): self
    {
        $this->total = $total;

        return $this;
    }

    /**
     * Only shown to roles that may see cost / profit (never cashiers, mechanics, clerks).
     */
    public function costOnly(bool $costOnly = true): self
    {
        $this->costOnly = $costOnly;

        return $this;
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, ['money', 'qty', 'int', 'percent'], true);
    }

    /**
     * Human format for screen and PDF.
     */
    public function format(mixed $value, bool $withSymbol = true): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($this->type) {
            'money' => ((float) $value < 0 ? '-' : '').($withSymbol ? '₱' : '').number_format(abs((float) $value), 2),
            'qty' => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            'int' => number_format((float) $value),
            'percent' => number_format((float) $value, 1).'%',
            'date' => CarbonImmutable::parse($value)->format('M d, Y'),
            'datetime' => CarbonImmutable::parse($value)->format('M d, Y h:i A'),
            default => (string) $value,
        };
    }
}
