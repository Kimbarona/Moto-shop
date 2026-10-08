<?php

namespace App\Reports\Audit;

use App\Models\Order;
use App\Models\ReprintLog;
use App\Models\SalesReturn;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

class ReprintsReport extends Report
{
    public static function key(): string
    {
        return 'reprints';
    }

    public static function title(): string
    {
        return 'Reprints';
    }

    public static function group(): string
    {
        return 'Audit';
    }

    public static function description(): string
    {
        return 'Every slip, job ticket and return slip printed again, by whom.';
    }

    protected function sections(Period $period): array
    {
        $rows = ReprintLog::query()
            ->with(['user', 'printable'])
            ->whereBetween('created_at', $period->range())
            ->orderBy('created_at')
            ->get()
            ->map(fn (ReprintLog $log) => [
                'date' => $log->created_at?->toDateTimeString(),
                'document' => ucwords(str_replace('_', ' ', $log->document)),
                'number' => match (true) {
                    $log->printable instanceof Order => $log->printable->slip_no ?? $log->printable->order_no,
                    $log->printable instanceof SalesReturn => $log->printable->return_no,
                    default => '#'.$log->printable_id,
                },
                'user' => $log->user?->name,
                'ip' => $log->ip_address,
            ])
            ->all();

        return [new Section([
            Column::datetime('date', 'Date'),
            Column::text('document', 'Document'),
            Column::text('number', 'No.'),
            Column::text('user', 'Printed by'),
            Column::text('ip', 'IP address'),
        ], $rows)];
    }
}
