<?php

namespace App\Support;

use App\Models\ManagerApproval;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReprintLog;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Human-readable history of an order from the audit log, manager approvals and reprints.
 */
class OrderTimeline
{
    /**
     * @return list<array{at: string|null, user: string|null, event: string, text: string}>
     */
    public static function for(Order $order): array
    {
        $itemIds = Activity::query()
            ->where('subject_type', (new OrderItem)->getMorphClass())
            ->where('event', 'created')
            ->where('properties->attributes->order_id', $order->id)
            ->pluck('subject_id');

        $entries = Activity::query()
            ->with('causer')
            ->where(fn ($q) => $q
                ->where(fn ($o) => $o->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id))
                ->orWhere(fn ($i) => $i->where('subject_type', (new OrderItem)->getMorphClass())->whereIn('subject_id', $itemIds)))
            ->orderBy('id')
            ->get()
            ->map(fn (Activity $activity) => [
                'at' => $activity->created_at?->toIso8601String(),
                'user' => $activity->causer?->name,
                'event' => (string) $activity->event,
                'text' => static::describe($activity),
            ])
            ->filter(fn (array $entry) => $entry['text'] !== '');

        $approvals = ManagerApproval::query()
            ->with(['approver', 'requester'])
            ->where('subject_type', $order->getMorphClass())
            ->where('subject_id', $order->id)
            ->get()
            ->map(fn (ManagerApproval $approval) => [
                'at' => $approval->created_at?->toIso8601String(),
                'user' => $approval->approver?->name,
                'event' => 'approved',
                'text' => sprintf(
                    '%s approved: %s%s',
                    $approval->approver?->name,
                    $approval->action->getLabel(),
                    isset($approval->details['percent']) ? ' ('.$approval->details['percent'].'%)' : '',
                ),
            ]);

        $reprints = ReprintLog::query()
            ->with('user')
            ->where('printable_type', $order->getMorphClass())
            ->where('printable_id', $order->id)
            ->get()
            ->map(fn (ReprintLog $log) => [
                'at' => $log->created_at?->toIso8601String(),
                'user' => $log->user?->name,
                'event' => 'reprint',
                'text' => 'Reprinted '.str_replace('_', ' ', $log->document),
            ]);

        return (new Collection)->merge($entries)->merge($approvals)->merge($reprints)
            ->sortBy('at')
            ->values()
            ->all();
    }

    private static function describe(Activity $activity): string
    {
        $attributes = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];
        $isItem = str_contains((string) $activity->subject_type, 'OrderItem');

        if ($isItem) {
            $name = $attributes['description'] ?? $old['description'] ?? $activity->subject?->description ?? 'item';

            return match ($activity->event) {
                'created' => sprintf('Added %s × %s', static::qty($attributes['qty'] ?? 1), $name),
                'deleted' => sprintf('Removed %s', $name),
                'updated' => static::changes($name, $attributes, $old),
                default => '',
            };
        }

        return match ($activity->event) {
            'created' => 'Order created',
            'paid' => sprintf('Paid · slip %s · %s', $activity->properties['slip_no'] ?? '', Money::format($activity->properties['total'] ?? 0)),
            'voided' => 'Voided: '.($activity->properties['reason'] ?? ''),
            'returned' => sprintf(
                'Return %s · %s · %s',
                $activity->properties['return_no'] ?? '',
                ucfirst((string) ($activity->properties['resolution'] ?? '')),
                Money::format($activity->properties['return_total'] ?? 0),
            ),
            'package_added' => 'Package added: '.($activity->properties['package'] ?? ''),
            'reprint' => '', // shown from reprint_logs instead
            'approved' => '', // shown from manager_approvals instead
            'updated' => static::orderChanges($attributes),
            default => (string) $activity->description,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     */
    private static function changes(string $name, array $attributes, array $old): string
    {
        $parts = [];

        if (array_key_exists('qty', $attributes)) {
            $parts[] = 'qty '.static::qty($old['qty'] ?? 0).' → '.static::qty($attributes['qty']);
        }

        if (array_key_exists('unit_price', $attributes)) {
            $parts[] = 'price '.Money::format($old['unit_price'] ?? 0).' → '.Money::format($attributes['unit_price']);
        }

        if (array_key_exists('discount_value', $attributes)) {
            $parts[] = 'discount '.(($attributes['discount_type'] ?? null) === 'percent'
                ? static::qty($attributes['discount_value']).'%'
                : Money::format($attributes['discount_value']));
        }

        if (array_key_exists('mechanic_id', $attributes)) {
            $parts[] = 'mechanic changed';
        }

        return $parts === [] ? '' : $name.': '.implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function orderChanges(array $attributes): string
    {
        $parts = [];

        if (array_key_exists('service_status', $attributes)) {
            $parts[] = 'status → '.str_replace('_', ' ', (string) $attributes['service_status']);
        }

        if (array_key_exists('discount_value', $attributes)) {
            $parts[] = 'order discount '.(($attributes['discount_type'] ?? null) === 'percent'
                ? static::qty($attributes['discount_value']).'%'
                : Money::format($attributes['discount_value']));
        }

        foreach (['customer_id' => 'customer', 'customer_motorcycle_id' => 'motorcycle', 'odometer_in' => 'odometer', 'complaint' => 'complaint', 'notes' => 'notes'] as $key => $label) {
            if (array_key_exists($key, $attributes)) {
                $parts[] = $label.' updated';
            }
        }

        return $parts === [] ? '' : ucfirst(implode(', ', $parts));
    }

    private static function qty(mixed $qty): string
    {
        return rtrim(rtrim(number_format((float) $qty, 2), '0'), '.');
    }
}
