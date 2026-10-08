<?php

namespace App\Reports\Audit;

use App\Enums\ApprovalAction;
use App\Models\ManagerApproval;
use App\Models\Order;
use App\Reports\Column;
use App\Reports\Period;
use App\Reports\Report;
use App\Reports\Section;

/**
 * Manager PIN approvals for discounts and price overrides (and, optionally, all).
 */
class ApprovalsReport extends Report
{
    public static function key(): string
    {
        return 'discount-approvals';
    }

    public static function title(): string
    {
        return 'Discount Approvals';
    }

    public static function group(): string
    {
        return 'Audit';
    }

    public static function description(): string
    {
        return 'Discounts and price overrides above the cashier limit, and who approved them.';
    }

    public static function optionFields(): array
    {
        return [
            'action' => [
                'label' => 'Approval type',
                'type' => 'select',
                'options' => ['discounts' => 'Discounts & price overrides', 'all' => 'All approvals', ...ApprovalAction::options()],
                'default' => 'discounts',
            ],
        ];
    }

    protected function sections(Period $period): array
    {
        $action = $this->option('action');

        $rows = ManagerApproval::query()
            ->with(['approver', 'requester', 'subject'])
            ->whereBetween('created_at', $period->range())
            ->when($action === 'discounts', fn ($q) => $q->whereIn('action', [ApprovalAction::Discount, ApprovalAction::PriceOverride]))
            ->when(! in_array($action, ['discounts', 'all'], true), fn ($q) => $q->where('action', $action))
            ->orderBy('created_at')
            ->get()
            ->map(function (ManagerApproval $a) {
                $details = $a->details ?? [];
                $subject = $a->subject;

                return [
                    'date' => $a->created_at?->toDateTimeString(),
                    'action' => $a->action->getLabel(),
                    'order' => $subject instanceof Order ? ($subject->slip_no ?? $subject->order_no) : (($details['scope'] ?? null) === 'cart' ? 'Counter sale' : null),
                    'percent' => isset($details['percent']) ? (float) $details['percent'] : null,
                    'detail' => collect($details)->except(['percent', 'scope'])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_scalar($v) ? $v : json_encode($v)))->implode(', '),
                    'requested_by' => $a->requester?->name,
                    'approved_by' => $a->approver?->name,
                ];
            })
            ->all();

        return [new Section([
            Column::datetime('date', 'Date'),
            Column::text('action', 'Approval'),
            Column::text('order', 'Order'),
            Column::percent('percent', 'Discount %'),
            Column::text('detail', 'Details'),
            Column::text('requested_by', 'Requested by'),
            Column::text('approved_by', 'Approved by'),
        ], $rows)];
    }
}
