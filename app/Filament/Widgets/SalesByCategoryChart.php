<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Services\Reports\SalesLedger;
use Filament\Widgets\ChartWidget;

class SalesByCategoryChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 3];

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function getHeading(): string
    {
        return 'By category';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = array_slice(app(SalesLedger::class)->byCategory($this->period()), 0, 10);

        return [
            'datasets' => [[
                'label' => 'Net sales',
                'data' => array_map(fn (array $r) => $r['net'], $rows),
                'backgroundColor' => 'rgba(255, 107, 26, 0.75)',
                'hoverBackgroundColor' => '#FF6B1A',
                'borderRadius' => 4,
            ]],
            'labels' => array_map(fn (array $r) => $r['category'], $rows),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
