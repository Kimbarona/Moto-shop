<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Reports\Period;
use App\Services\Reports\SalesLedger;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Net sales per day. Short periods (today, a few days) show at least the last 7 days.
 */
class SalesByDayChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 4];

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function getHeading(): string
    {
        return 'Sales by day';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $period = $this->period();

        if (count($period->days()) < 7) {
            $period = new Period($period->until->subDays(6), $period->until);
        }

        $days = app(SalesLedger::class)->daily($period);

        return [
            'datasets' => [
                [
                    'label' => 'Net sales',
                    'data' => array_values(array_map(fn (array $d) => $d['net'], $days)),
                    'backgroundColor' => '#FF6B1A',
                    'borderRadius' => 4,
                ],
                [
                    'label' => 'Refunds',
                    'data' => array_values(array_map(fn (array $d) => $d['refunds'], $days)),
                    'backgroundColor' => '#a855f7',
                    'borderRadius' => 4,
                ],
            ],
            'labels' => array_map(fn (string $day) => CarbonImmutable::parse($day)->format('M d'), array_keys($days)),
        ];
    }
}
