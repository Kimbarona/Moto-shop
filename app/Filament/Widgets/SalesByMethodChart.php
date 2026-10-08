<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Services\Reports\SalesLedger;
use Filament\Widgets\ChartWidget;

/**
 * Money received (less refunds paid out) by Cash / E-Wallet / Bank.
 */
class SalesByMethodChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 2];

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function getHeading(): string
    {
        return 'By payment method';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $types = ['cash' => 'Cash', 'ewallet' => 'E-Wallet', 'bank' => 'Bank'];
        $totals = array_fill_keys(array_keys($types), 0.0);

        foreach (app(SalesLedger::class)->byPaymentMethod($this->period()) as $row) {
            $totals[$row['type']] = round(($totals[$row['type']] ?? 0) + $row['net'], 2);
        }

        return [
            'datasets' => [[
                'data' => array_values($totals),
                'backgroundColor' => ['#22C55E', '#0EA5E9', '#FFC93C'],
                'borderWidth' => 0,
            ]],
            'labels' => array_values($types),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'cutout' => '62%',
        ];
    }
}
