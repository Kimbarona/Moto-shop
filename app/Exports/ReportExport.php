<?php

namespace App\Exports;

use App\Reports\ReportResult;
use App\Reports\Section;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * A report as an Excel workbook: one sheet per table, numbers stored as numbers.
 */
class ReportExport implements WithMultipleSheets
{
    public function __construct(private readonly ReportResult $result) {}

    public function sheets(): array
    {
        return array_map(
            fn (Section $section, int $i) => new ReportSheet($this->result, $section, $i),
            $this->result->sections,
            array_keys($this->result->sections),
        );
    }
}
