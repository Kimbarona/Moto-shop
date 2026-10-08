<?php

namespace App\Reports;

use App\Models\User;

/**
 * A back-office report: a date range, a few optional options, then one or more tables
 * (sections) and a strip of key figures. Rendered on the Reports page and exported to
 * Excel and PDF from the same result, so all three always agree.
 *
 * Cost / profit columns are marked costOnly() and stripped for roles without "view-cost".
 */
abstract class Report
{
    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    abstract public static function key(): string;

    abstract public static function title(): string;

    /**
     * Sales, Profit, Inventory, Service, Returns, Audit, Expenses.
     */
    abstract public static function group(): string;

    abstract public static function description(): string;

    /**
     * @return list<Section>
     */
    abstract protected function sections(Period $period): array;

    /**
     * Ability that may open the report.
     */
    public static function ability(): string
    {
        return 'view-reports';
    }

    /**
     * Point-in-time reports (current stock) ignore the date range.
     */
    public static function usesDates(): bool
    {
        return true;
    }

    public static function canBeViewedBy(?User $user): bool
    {
        return $user !== null && $user->can(static::ability());
    }

    /**
     * Extra inputs: [name => [label, type: select|number, options?, default]].
     *
     * @return array<string, array{label: string, type: string, options?: array<string, string>, default: mixed}>
     */
    public static function optionFields(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): static
    {
        $this->options = [];

        foreach (static::optionFields() as $name => $field) {
            $value = $options[$name] ?? null;
            $this->options[$name] = filled($value) ? $value : $field['default'];
        }

        return $this;
    }

    protected function option(string $name): mixed
    {
        return $this->options[$name] ?? (static::optionFields()[$name]['default'] ?? null);
    }

    /**
     * Key figures shown above the tables: [label => [value, type]].
     *
     * @return array<string, array{0: mixed, 1: string, 2?: bool}> third item: cost only
     */
    protected function summary(Period $period): array
    {
        return [];
    }

    public function run(Period $period, User $viewer): ReportResult
    {
        $viewCost = $viewer->can('view-cost');

        $summary = collect($this->summary($period))
            ->reject(fn (array $figure) => ($figure[2] ?? false) && ! $viewCost)
            ->map(fn (array $figure) => ['value' => $figure[0], 'type' => $figure[1]])
            ->all();

        return new ReportResult(
            title: static::title(),
            period: static::usesDates() ? $period->label() : 'As of '.now()->format('M d, Y h:i A'),
            summary: $summary,
            sections: array_map(fn (Section $section) => $section->forViewer($viewCost), $this->sections($period)),
            options: $this->optionLabels(),
        );
    }

    /**
     * @return array<string, string> human description of the chosen options
     */
    private function optionLabels(): array
    {
        $labels = [];

        foreach (static::optionFields() as $name => $field) {
            $value = $this->option($name);
            $labels[$field['label']] = (string) ($field['options'][$value] ?? $value);
        }

        return $labels;
    }

    protected static function pct(float $part, float $whole): ?float
    {
        return $whole != 0.0 ? round($part / $whole * 100, 1) : null;
    }
}
