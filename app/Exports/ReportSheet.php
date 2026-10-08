<?php

namespace App\Exports;

use App\Reports\Column;
use App\Reports\ReportResult;
use App\Reports\Section;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One report table as a sheet: title, period and options on top (plus the key figures on
 * the first sheet), then the table with a totals row. Numbers stay numbers (zeros
 * included) and get number formats per cell range, so Excel can sum them.
 */
class ReportSheet implements FromArray, ShouldAutoSize, WithStrictNullComparison, WithStyles, WithTitle
{
    private const FORMATS = [
        'money' => '#,##0.00;-#,##0.00',
        'qty' => '#,##0.##',
        'int' => '#,##0',
        'percent' => '0.0"%"',
    ];

    /** @var array<int, string> summary row => figure type */
    private array $summaryRows = [];

    private int $headingRow = 0;

    private int $lastRow = 0;

    public function __construct(
        private readonly ReportResult $result,
        private readonly Section $section,
        private readonly int $index,
    ) {}

    public function title(): string
    {
        // Sheet names: unique, max 31 chars, no []:*?/\ characters.
        $name = $this->section->title ?? ($this->index === 0 ? 'Summary' : 'Table '.($this->index + 1));

        return substr(preg_replace('/[\[\]:*?\/\\\\]/', '', $name) ?: 'Sheet '.($this->index + 1), 0, 31);
    }

    public function array(): array
    {
        $this->summaryRows = [];

        $rows = [
            [$this->result->title],
            [$this->result->period],
        ];

        foreach ($this->result->options as $label => $value) {
            $rows[] = [$label.': '.$value];
        }

        if ($this->index === 0) {
            foreach ($this->result->summary as $label => $figure) {
                $rows[] = [$label, $figure['value'] === null ? null : (float) $figure['value']];
                $this->summaryRows[count($rows)] = $figure['type'];
            }
        }

        if ($this->section->title) {
            $rows[] = [null]; // blank line ([] would be skipped)
            $rows[] = [$this->section->title];
        }

        $rows[] = [null];
        $rows[] = array_map(fn (Column $c) => $c->label, $this->section->columns);
        $this->headingRow = count($rows);

        foreach ($this->section->rows as $row) {
            $rows[] = array_map(fn (Column $c) => $this->cell($c, $row[$c->key] ?? null), $this->section->columns);
        }

        if ($totals = $this->section->totals()) {
            $rows[] = array_map(
                fn (Column $c, int $i) => $i === 0 ? 'TOTAL' : ($totals[$c->key] ?? null),
                $this->section->columns,
                array_keys($this->section->columns),
            );
        }

        $this->lastRow = count($rows);

        return $rows;
    }

    private function cell(Column $column, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($column->type) {
            'money', 'qty', 'percent' => (float) $value,
            'int' => (int) $value,
            'date' => CarbonImmutable::parse($value)->format('M d, Y'),
            'datetime' => CarbonImmutable::parse($value)->format('M d, Y h:i A'),
            default => (string) $value,
        };
    }

    public function styles(Worksheet $sheet): array
    {
        $this->array(); // works out the row positions

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->headingRow => ['font' => ['bold' => true]],
        ];

        foreach ($this->summaryRows as $row => $type) {
            if (isset(self::FORMATS[$type])) {
                $styles["B{$row}"] = ['numberFormat' => ['formatCode' => self::FORMATS[$type]], 'font' => ['bold' => true]];
            }
        }

        if ($this->lastRow > $this->headingRow) {
            foreach ($this->section->columns as $i => $column) {
                if (isset(self::FORMATS[$column->type])) {
                    $letter = Coordinate::stringFromColumnIndex($i + 1);
                    $styles["{$letter}".($this->headingRow + 1).":{$letter}{$this->lastRow}"] = ['numberFormat' => ['formatCode' => self::FORMATS[$column->type]]];
                }
            }

            if ($this->section->totals()) {
                $styles[$this->lastRow] = ['font' => ['bold' => true]];
            }
        }

        return $styles;
    }
}
