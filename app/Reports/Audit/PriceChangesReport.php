<?php

namespace App\Reports\Audit;

use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;
use Spatie\Activitylog\Models\Activity;

/**
 * SRP, labor fee and package price changes from the audit log. Average-cost changes
 * (manual edits and stock-in recalculations) are listed for cost viewers only.
 */
class PriceChangesReport extends Report
{
    /**
     * @var array<class-string, array<string, string>> model => [attribute => label]
     */
    private const FIELDS = [
        Product::class => ['srp' => 'SRP', 'avg_cost' => 'Avg cost'],
        Service::class => ['labor_fee' => 'Labor fee'],
        ServicePackage::class => ['package_price' => 'Package price'],
    ];

    public static function key(): string
    {
        return 'price-changes';
    }

    public static function title(): string
    {
        return 'Price Changes';
    }

    public static function group(): string
    {
        return 'Audit';
    }

    public static function description(): string
    {
        return 'Old and new SRP, labor fees and package prices, with who changed them.';
    }

    protected function sections(Period $period): array
    {
        $viewCost = auth()->user()?->can('view-cost') ?? false;

        $rows = Activity::query()
            ->with(['causer', 'subject'])
            ->whereIn('subject_type', array_map(fn ($class) => (new $class)->getMorphClass(), array_keys(self::FIELDS)))
            ->where('event', 'updated')
            ->whereBetween('created_at', $period->range())
            ->orderBy('created_at')
            ->get()
            ->flatMap(function (Activity $activity) use ($viewCost) {
                $fields = collect(self::FIELDS)->first(fn ($f, $class) => (new $class)->getMorphClass() === $activity->subject_type) ?? [];
                $new = $activity->properties['attributes'] ?? [];
                $old = $activity->properties['old'] ?? [];

                return collect($fields)
                    ->filter(fn ($label, $field) => array_key_exists($field, $new) && ($field !== 'avg_cost' || $viewCost))
                    ->map(fn ($label, $field) => [
                        'date' => $activity->created_at?->toDateTimeString(),
                        'item' => $activity->subject?->name ?? '#'.$activity->subject_id,
                        'field' => $label,
                        'old' => isset($old[$field]) ? (float) $old[$field] : null,
                        'new' => $new[$field] !== null ? (float) $new[$field] : null,
                        'change' => isset($old[$field], $new[$field]) ? round((float) $new[$field] - (float) $old[$field], 2) : null,
                        'user' => $activity->causer?->name,
                    ])
                    ->values();
            })
            ->values()
            ->all();

        return [new Section([
            Column::datetime('date', 'Date'),
            Column::text('item', 'Item'),
            Column::text('field', 'Price'),
            Column::money('old', 'Old'),
            Column::money('new', 'New'),
            Column::money('change', 'Change'),
            Column::text('user', 'Changed by'),
        ], $rows, showTotals: false)];
    }
}
