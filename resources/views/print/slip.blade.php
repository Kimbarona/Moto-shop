@extends('print.layout')

@php
    use App\Support\Money;
    /** @var \App\Models\Order $order */
    $payment = $order->payments->sortByDesc('id')->first();
    $lineDiscounts = $order->items->sum(fn ($i) => (float) $i->discount);
    $orderDiscount = (float) $order->discount_total - $lineDiscounts;
    $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
@endphp

@section('title', $slip->document_title.' '.$order->slip_no)

@section('body')
    <div class="center">
        <div class="title h">{{ $slip->document_title }}</div>
        @if ($reprint)<div class="badge">REPRINT</div>@endif
    </div>
    @if ($order->isVoided())
        <div class="stamp">VOIDED</div>
    @endif

    <table class="meta small">
        <tr><td>Slip no.</td><td class="bold">{{ $order->slip_no }}</td></tr>
        <tr><td>Order no.</td><td>{{ $order->order_no }}</td></tr>
        <tr><td>Date</td><td>{{ $order->paid_at?->format('M d, Y h:i A') }}</td></tr>
        <tr><td>Cashier</td><td>{{ $order->cashier?->name }}</td></tr>
        @if ($order->customer)
            <tr><td>Customer</td><td>{{ $order->customer->name }}</td></tr>
        @endif
        @if ($order->motorcycle)
            <tr><td>Motorcycle</td><td>{{ trim(($order->motorcycle->plate_no ?? 'No plate').' · '.($order->motorcycle->motorcycleModel?->full_name ?? ''), ' ·') }}</td></tr>
        @endif
        @if ($order->odometer_in)
            <tr><td>Odometer</td><td>{{ number_format($order->odometer_in) }} km</td></tr>
        @endif
        @if ($order->mechanics->isNotEmpty())
            <tr><td>Mechanic</td><td>{{ $order->mechanics->pluck('name')->implode(', ') }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>

    <table>
        <thead>
            <tr><th>Item</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td colspan="2">{{ $item->description }}</td>
                </tr>
                <tr class="small">
                    <td class="muted">&nbsp;&nbsp;{{ $qty($item->qty) }} × {{ Money::format($item->unit_price) }}</td>
                    <td class="num">{{ Money::format($item->gross()) }}</td>
                </tr>
                @if ((float) $item->discount > 0)
                    <tr class="small">
                        <td class="muted">&nbsp;&nbsp;Less discount{{ $item->discount_type?->value === 'percent' ? ' '.$qty($item->discount_value).'%' : '' }}</td>
                        <td class="num">-{{ Money::format($item->discount) }}</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <div class="rule"></div>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ Money::format($order->subtotal) }}</td></tr>
        @if ((float) $order->discount_total > 0)
            <tr><td>Discount</td><td class="num">-{{ Money::format($order->discount_total) }}</td></tr>
        @endif
        @if ($tax->vat_registered)
            <tr class="small"><td>VATable sales</td><td class="num">{{ Money::format($order->vatable) }}</td></tr>
            <tr class="small"><td>VAT ({{ $qty($tax->vat_rate) }}%)</td><td class="num">{{ Money::format($order->vat_amount) }}</td></tr>
        @endif
        @if ((float) $order->return_credit > 0)
            <tr><td>Return credit</td><td class="num">-{{ Money::format($order->return_credit) }}</td></tr>
        @endif
        <tr class="grand"><td>TOTAL</td><td class="num">{{ Money::format($order->amountDue()) }}</td></tr>
        @if ($payment)
            <tr><td>{{ $payment->method?->name }}</td><td class="num">{{ Money::format($payment->amount_tendered ?? $payment->amount) }}</td></tr>
            @if ((float) $payment->change_due > 0)
                <tr><td>Change</td><td class="num">{{ Money::format($payment->change_due) }}</td></tr>
            @endif
            @if ($payment->reference_no)
                <tr class="small"><td>Ref no.</td><td class="num">{{ $payment->reference_no }}</td></tr>
            @endif
        @endif
    </table>

    <div class="tread"></div>
    <div class="center small">{!! nl2br(e($slip->footer_text)) !!}</div>
    <div class="center small muted" style="margin-top:1mm">Thank you! Ride safe.</div>
@endsection
