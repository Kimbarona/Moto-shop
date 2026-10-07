<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My summary": a cashier's own sales for a day by payment method; a mechanic's jobs and
 * commission for a period. Only the signed-in user's own figures, never cost.
 */
class SummaryController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $from = Carbon::parse($request->input('from', today()))->startOfDay();
        $until = Carbon::parse($request->input('until', $from))->endOfDay();

        $props = ['from' => $from->toDateString(), 'until' => $until->toDateString(), 'cashier' => null, 'mechanic' => null];

        if ($user->can('take-payment')) {
            $payments = Payment::query()
                ->notVoided()
                ->with('method', 'order')
                ->where('received_by', $user->id)
                ->whereBetween('paid_at', [$from, $until])
                ->get();

            $voided = Order::query()->where('cashier_id', $user->id)->whereNotNull('voided_at')
                ->whereBetween('voided_at', [$from, $until])->count();

            $props['cashier'] = [
                'orders' => $payments->pluck('order_id')->unique()->count(),
                'total' => round((float) $payments->sum('amount'), 2),
                'voided' => $voided,
                'byMethod' => $payments->groupBy(fn (Payment $p) => $p->method?->name ?? 'Unknown')
                    ->map(fn ($group, $name) => [
                        'method' => $name,
                        'count' => $group->count(),
                        'amount' => round((float) $group->sum('amount'), 2),
                        'references' => $group->pluck('reference_no')->filter()->values(),
                    ])->values(),
                'recent' => $payments->sortByDesc('paid_at')->take(20)->map(fn (Payment $p) => [
                    'order_id' => $p->order_id,
                    'slip_no' => $p->order?->slip_no,
                    'method' => $p->method?->name,
                    'amount' => (float) $p->amount,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                ])->values(),
            ];
        }

        if ($user->isMechanic() && $user->mechanic) {
            $lines = OrderItem::query()
                ->with('order')
                ->where('mechanic_id', $user->mechanic->id)
                ->where('line_type', 'labor')
                ->whereHas('order', fn ($q) => $q->paid()->whereBetween('paid_at', [$from, $until]))
                ->get();

            $props['mechanic'] = [
                'jobs' => $lines->pluck('order_id')->unique()->count(),
                'labor' => round($lines->sum(fn (OrderItem $item) => $item->netAmount()), 2),
                'commission' => round((float) $lines->sum('commission_amount'), 2),
                'openJobs' => Order::query()->pending()->whereHas('mechanics', fn ($m) => $m->where('user_id', $user->id))->count(),
                'lines' => $lines->sortByDesc(fn ($item) => $item->order?->paid_at)->map(fn (OrderItem $item) => [
                    'slip_no' => $item->order?->slip_no,
                    'description' => $item->description,
                    'net' => $item->netAmount(),
                    'commission' => (float) $item->commission_amount,
                    'paid_at' => $item->order?->paid_at?->toIso8601String(),
                ])->values(),
            ];
        }

        return Inertia::render('my-summary', $props);
    }
}
