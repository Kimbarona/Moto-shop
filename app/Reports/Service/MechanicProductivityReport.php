<?php

namespace App\Reports\Service;

use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

class MechanicProductivityReport extends Report
{
    use MechanicReport;

    public static function key(): string
    {
        return 'mechanic-productivity';
    }

    public static function title(): string
    {
        return 'Mechanic Productivity';
    }

    public static function group(): string
    {
        return 'Service';
    }

    public static function description(): string
    {
        return 'Jobs, labor lines and net labor amount per mechanic.';
    }

    protected function sections(Period $period): array
    {
        $rows = array_values(array_map(fn (array $m) => [
            'mechanic' => $m['mechanic'],
            'jobs' => $m['jobs'],
            'lines' => $m['lines'],
            'labor' => $m['labor'],
            'refunded' => $m['labor_refunded'] > 0 ? -$m['labor_refunded'] : 0.0,
            'net' => round($m['labor'] - $m['labor_refunded'], 2),
            'per_job' => $m['jobs'] > 0 ? round(($m['labor'] - $m['labor_refunded']) / $m['jobs'], 2) : null,
        ], $this->mechanicFigures($period)));

        return [new Section([
            Column::text('mechanic', 'Mechanic'),
            Column::int('jobs', 'Jobs')->total(),
            Column::int('lines', 'Labor lines')->total(),
            Column::money('labor', 'Labor')->total(),
            Column::money('refunded', 'Refunded')->total(),
            Column::money('net', 'Net labor')->total(),
            Column::money('per_job', 'Avg per job'),
        ], $rows)];
    }
}
