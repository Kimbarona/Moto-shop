<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns raw activity-log rows into something a shop owner can read: friendly area
 * names, a label for the record, and a before → after list of changed fields.
 */
class AuditLog
{
    /**
     * log_name => label shown in the viewer.
     */
    public const AREAS = [
        'Order' => 'Order',
        'OrderItem' => 'Order line',
        'Payment' => 'Payment',
        'SalesReturn' => 'Return',
        'ManagerApproval' => 'Manager approval',
        'ManagerPin' => 'Manager PIN',
        'Product' => 'Product',
        'StockReceiving' => 'Stock in',
        'StockAdjustment' => 'Stock adjustment',
        'StockCount' => 'Physical count',
        'DefectiveStock' => 'Defective stock',
        'Settings' => 'App settings',
        'Backup' => 'Backup',
        'Report' => 'Report export',
        'User' => 'User',
        'Customer' => 'Customer',
        'CustomerMotorcycle' => 'Customer motorcycle',
        'Expense' => 'Expense',
        'ExpenseCategory' => 'Expense category',
        'Category' => 'Category',
        'Brand' => 'Brand',
        'Unit' => 'Unit',
        'Supplier' => 'Supplier',
        'Mechanic' => 'Mechanic',
        'Service' => 'Service',
        'ServicePackage' => 'Service package',
        'MotorcycleMake' => 'Motorcycle make',
        'MotorcycleModel' => 'Motorcycle model',
        'PaymentMethod' => 'Payment method',
        'Sequence' => 'Numbering',
    ];

    /**
     * event => [label, Filament colour].
     */
    public const EVENTS = [
        'created' => ['Created', 'success'],
        'updated' => ['Updated', 'info'],
        'deleted' => ['Deleted', 'danger'],
        'restored' => ['Restored', 'success'],
        'paid' => ['Paid', 'success'],
        'voided' => ['Voided', 'gray'],
        'returned' => ['Returned', 'purple'],
        'approved' => ['Approved', 'warning'],
        'posted' => ['Posted', 'success'],
        'cancelled' => ['Cancelled', 'gray'],
        'reprint' => ['Reprint', 'gray'],
        'exported' => ['Exported', 'gray'],
        'imported' => ['Imported', 'info'],
        'backup' => ['Backup', 'info'],
        'package_added' => ['Package added', 'info'],
        'pin_changed' => ['PIN changed', 'warning'],
        'pin_failed' => ['Wrong PIN', 'danger'],
        'password_changed' => ['Password changed', 'warning'],
        'password_reset' => ['Password reset', 'warning'],
    ];

    /**
     * Properties that are bookkeeping, not part of the change itself.
     */
    private const META = ['ip', 'old', 'attributes'];

    public static function area(?string $logName): string
    {
        return self::AREAS[$logName] ?? Str::headline((string) $logName);
    }

    public static function eventLabel(?string $event): string
    {
        return self::EVENTS[$event][0] ?? Str::headline((string) $event);
    }

    public static function eventColor(?string $event): string
    {
        return self::EVENTS[$event][1] ?? 'gray';
    }

    /**
     * One-line description. Automatic model logs only say "created"/"updated", so
     * those get the area and record label added.
     */
    public static function summary(Activity $activity): string
    {
        $description = (string) $activity->description;

        if ($description !== '' && $description !== $activity->event) {
            return $description;
        }

        return trim(sprintf('%s %s %s', self::area($activity->log_name), self::recordLabel($activity), strtolower(self::eventLabel($activity->event))));
    }

    /**
     * Document number, SKU or name of the record, falling back to its ID.
     */
    public static function recordLabel(Activity $activity): string
    {
        if (! $activity->subject_id) {
            return '';
        }

        $values = array_merge(
            (array) $activity->properties->get('old', []),
            (array) $activity->properties->get('attributes', []),
        );

        // Eager-loaded in the table; deleted records fall back to the logged values.
        $subject = $activity->subject;

        if ($subject instanceof Model) {
            $values = array_merge($values, $subject->only(['order_no', 'return_no', 'receiving_no', 'adjustment_no', 'count_no', 'sku', 'name', 'description']));
        }

        foreach (['order_no', 'return_no', 'receiving_no', 'adjustment_no', 'count_no', 'sku'] as $key) {
            if (filled($values[$key] ?? null)) {
                return (string) $values[$key];
            }
        }

        foreach (['name', 'description'] as $key) {
            if (filled($values[$key] ?? null) && is_string($values[$key])) {
                return '“'.Str::limit($values[$key], 40).'”';
            }
        }

        return '#'.$activity->subject_id;
    }

    /**
     * Changed fields as [field, before, after], formatted for display. Created
     * records have no "before"; other events list their extra details.
     *
     * @return list<array{field: string, old: string|null, new: string}>
     */
    public static function changes(Activity $activity): array
    {
        $old = (array) $activity->properties->get('old', []);
        $new = (array) $activity->properties->get('attributes', []);
        $rows = [];

        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $field) {
            $rows[] = [
                'field' => self::fieldLabel((string) $field),
                'old' => array_key_exists($field, $old) ? self::format($old[$field]) : null,
                'new' => array_key_exists($field, $new) ? self::format($new[$field]) : '—',
            ];
        }

        foreach ($activity->properties->except(self::META)->all() as $field => $value) {
            $rows[] = ['field' => self::fieldLabel((string) $field), 'old' => null, 'new' => self::format($value)];
        }

        return $rows;
    }

    /**
     * Short "field: before → after" text for the table.
     */
    public static function changeSummary(Activity $activity, int $limit = 3): string
    {
        $rows = self::changes($activity);

        if ($activity->event === 'created') {
            return count($rows).' fields set';
        }

        $parts = array_map(
            fn (array $row) => $row['old'] === null
                ? "{$row['field']}: {$row['new']}"
                : "{$row['field']}: {$row['old']} → {$row['new']}",
            array_slice($rows, 0, $limit),
        );

        if (count($rows) > $limit) {
            $parts[] = '+'.(count($rows) - $limit).' more';
        }

        return implode(' · ', $parts);
    }

    public static function ip(Activity $activity): ?string
    {
        return $activity->properties->get('ip');
    }

    private static function fieldLabel(string $field): string
    {
        // Settings keys look like "pos.max_discount_percent".
        return implode(' › ', array_map(fn (string $part) => Str::of($part)->replace('_id', '')->headline()->toString(), explode('.', $field)));
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => Str::limit(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 200),
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1 => CarbonImmutable::parse($value)->timezone(config('app.timezone'))->format('M d, Y h:i A'),
            default => Str::limit((string) $value, 200),
        };
    }
}
