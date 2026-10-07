<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReprintLog;
use App\Models\SalesReturn;
use App\Settings\ShopSettings;
use App\Settings\SlipSettings;
use App\Settings\TaxSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Browser-printed documents (no printer drivers). The first print right after payment
 * / job creation is the original; any later print passes ?reprint=1, is marked REPRINT
 * and logged.
 */
class PrintController extends Controller
{
    public function slip(Request $request, Order $order): View
    {
        $this->authorize('view', $order);
        abort_unless($order->isPaid() || $order->isVoided(), 404, 'Only paid orders have an order slip.');

        return view('print.slip', [
            ...$this->common($request, $order, 'slip'),
            'order' => $order->load(['items', 'payments.method', 'customer', 'motorcycle.motorcycleModel.make', 'cashier', 'mechanics', 'exchangeReturn', 'returns']),
        ]);
    }

    public function jobTicket(Request $request, Order $order): View
    {
        $this->authorize('view', $order);
        abort_unless($order->isService(), 404, 'Only service orders have a job ticket.');

        return view('print.job-ticket', [
            ...$this->common($request, $order, 'job_ticket'),
            'order' => $order->load(['items.mechanic', 'customer', 'motorcycle.motorcycleModel.make', 'mechanics', 'creator']),
        ]);
    }

    public function returnSlip(Request $request, SalesReturn $salesReturn): View
    {
        return view('print.return-slip', [
            ...$this->common($request, $salesReturn, 'return_slip'),
            'return' => $salesReturn->load([
                'items.orderItem', 'order.customer', 'order.motorcycle.motorcycleModel.make',
                'refundMethod', 'approver', 'processor', 'exchangeOrder',
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function common(Request $request, Model $printable, string $document): array
    {
        $reprint = $request->boolean('reprint');

        if ($reprint) {
            ReprintLog::create([
                'document' => $document,
                'printable_type' => $printable->getMorphClass(),
                'printable_id' => $printable->getKey(),
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
            ]);

            activity(class_basename($printable))->causedBy($request->user())->performedOn($printable)->event('reprint')
                ->withProperties(['document' => $document])
                ->log('Reprinted '.str_replace('_', ' ', $document));
        }

        $slip = app(SlipSettings::class);

        return [
            'shop' => app(ShopSettings::class),
            'slip' => $slip,
            'tax' => app(TaxSettings::class),
            'reprint' => $reprint,
            'paper' => $slip->paper_size,
            'copies' => max(1, $slip->copies),
            'autoPrint' => ! $request->boolean('preview'),
        ];
    }
}
