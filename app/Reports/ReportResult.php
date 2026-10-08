<?php

namespace App\Reports;

/**
 * What a report produced for one viewer (cost columns already removed if needed).
 */
final class ReportResult
{
    /**
     * @param  array<string, array{value: mixed, type: string}>  $summary
     * @param  list<Section>  $sections
     * @param  array<string, string>  $options
     */
    public function __construct(
        public readonly string $title,
        public readonly string $period,
        public readonly array $summary,
        public readonly array $sections,
        public readonly array $options = [],
    ) {}

    public function formatFigure(array $figure): string
    {
        $column = match ($figure['type']) {
            'money' => Column::money('v', ''),
            'qty' => Column::qty('v', ''),
            'percent' => Column::percent('v', ''),
            'int' => Column::int('v', ''),
            default => Column::text('v', ''),
        };

        return $column->format($figure['value']);
    }
}
