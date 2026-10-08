<?php

namespace App\Filament\Widgets\Concerns;

use App\Reports\Period;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Reads the dashboard's period filter (Today / This Week / This Month / Custom).
 */
trait UsesDashboardPeriod
{
    use InteractsWithPageFilters;

    protected function period(): Period
    {
        $filters = $this->pageFilters ?? [];

        return Period::preset($filters['period'] ?? 'today', $filters['from'] ?? null, $filters['until'] ?? null);
    }

    protected function periodLabel(): string
    {
        return match ($this->pageFilters['period'] ?? 'today') {
            'week' => 'this week',
            'month' => 'this month',
            'custom' => $this->period()->label(),
            default => 'today',
        };
    }
}
