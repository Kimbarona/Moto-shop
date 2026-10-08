<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use DateTimeInterface;

/**
 * An inclusive date range in shop time (Asia/Manila): from 00:00:00 to until 23:59:59.
 */
final class Period
{
    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $until;

    public function __construct(mixed $from, mixed $until)
    {
        $from = CarbonImmutable::parse($from ?? today())->startOfDay();
        $until = CarbonImmutable::parse($until ?? $from)->endOfDay();

        if ($until->lt($from)) {
            [$from, $until] = [$until->startOfDay(), $from->endOfDay()];
        }

        $this->from = $from;
        $this->until = $until;
    }

    public static function today(): self
    {
        return new self(today(), today());
    }

    /**
     * Dashboard presets: today, week, month, or custom from/until.
     */
    public static function preset(?string $preset, mixed $from = null, mixed $until = null): self
    {
        $now = CarbonImmutable::now();

        return match ($preset) {
            'week' => new self($now->startOfWeek(), $now),
            'month' => new self($now->startOfMonth(), $now),
            'custom' => new self($from ?? $now, $until ?? $now),
            default => new self($now, $now),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        return [$this->from, $this->until];
    }

    /**
     * @return list<string> Y-m-d for every day in the period
     */
    public function days(): array
    {
        return collect(CarbonPeriod::create($this->from->toDateString(), $this->until->toDateString()))
            ->map(fn (DateTimeInterface $day) => $day->format('Y-m-d'))
            ->values()
            ->all();
    }

    public function label(): string
    {
        return $this->from->isSameDay($this->until)
            ? $this->from->format('M d, Y')
            : $this->from->format('M d, Y').' – '.$this->until->format('M d, Y');
    }
}
