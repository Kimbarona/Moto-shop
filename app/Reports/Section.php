<?php

namespace App\Reports;

/**
 * One table in a report result. Rows are plain arrays keyed by column key.
 */
final class Section
{
    /**
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public ?string $title = null,
        public bool $showTotals = true,
    ) {}

    /**
     * Hide cost / profit columns from roles that may not see them.
     */
    public function forViewer(bool $viewCost): self
    {
        if ($viewCost) {
            return $this;
        }

        $columns = array_values(array_filter($this->columns, fn (Column $c) => ! $c->costOnly));
        $keys = array_map(fn (Column $c) => $c->key, $columns);
        $rows = array_map(fn (array $row) => array_intersect_key($row, array_flip($keys)), $this->rows);

        return new self($columns, $rows, $this->title, $this->showTotals);
    }

    /**
     * @return array<string, float>|null sums of the columns marked total()
     */
    public function totals(): ?array
    {
        $summed = array_filter($this->columns, fn (Column $c) => $c->total);

        if (! $this->showTotals || $summed === [] || $this->rows === []) {
            return null;
        }

        $totals = [];

        foreach ($summed as $column) {
            $totals[$column->key] = round(array_sum(array_map(fn ($row) => (float) ($row[$column->key] ?? 0), $this->rows)), 2);
        }

        return $totals;
    }
}
