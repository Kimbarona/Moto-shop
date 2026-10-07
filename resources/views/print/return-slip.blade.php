@extends('print.layout')

@php
    use App\Support\Money;
    /** @var \App\Models\SalesReturn $return */
    $order = $return->order;
    $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
    $exchangeOrder = $return->exchangeOrder;
@endphp

@section('title', 'Return Slip '.$return->return_no)

@section('body')
    <div class="center">
        <div class="title h">Return Slip</div>
        @if ($reprint)<div class="badge">REPRINT</div>@endif
    </div>

    <table class="meta small">
        <tr><td>Return no.</td><td class="bold">{{ $return->return_no }}</td></tr>
        <tr><td>Date</td><td>{{ $return->date?->format('M d, Y h:i A') }}</td></tr>
        <tr><td>Original slip</td><td>{{ $order->slip_no }} · {{ $order->paid_at?->format('M d, Y') }}</td></tr>
        @if ($order->customer)
            <tr><td>Customer</td><td>{{ $order->customer->name }}</td></tr>
        @endif
        @if ($order->motorcycle)
            <tr><td>Motorcycle</td><td>{{ trim(($order->motorcycle->plate_no ?? 'No plate').' · '.($order->motorcycle->motorcycleModel?->full_name ?? ''), ' ·') }}</td></tr>
        @endif
        <tr><td>Reason</td><td>{{ $return->reason->getLabel() }}</td></tr>
        @if ($return->remarks)
            <tr><td>Remarks</td><td>{{ $return->remarks }}</td></tr>
        @endif
        <tr><td>Processed by</td><td>{{ $return->processor?->name }}</td></tr>
        @if ($return->approver && $return->approver->isNot($return->processor))
            <tr><td>Approved by</td><td>{{ $return->approver->name }}</td></tr>
        @endif
    </table>

    <div class="rule"></div>

    <table>
        <thead>
            <tr><th>Returned item</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($return->items as $line)
                <tr>
                    <td colspan="2">{{ $line->orderItem?->description }}</td>
                </tr>
                <tr class="small">
                    <td class="muted">&nbsp;&nbsp;{{ $qty($line->qty) }}{{ $line->disposition ? ' · '.$line->disposition->getLabel() : ' · Labor' }}</td>
                    <td class="num">-{{ Money::format($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="rule"></div>

    <table class="totals">
        <tr class="grand"><td>RETURN TOTAL</td><td class="num">{{ Money::format($return->return_total) }}</td></tr>
        @if ($return->isExchange())
            <tr><td>Resolution</td><td class="num">Exchange</td></tr>
            @if ($exchangeOrder)
                <tr><td>Credit used on</td><td class="num">{{ $exchangeOrder->slip_no }}</td></tr>
                <tr><td>Credit applied</td><td class="num">{{ Money::format($exchangeOrder->return_credit) }}</td></tr>
                @if ((float) $return->refund_amount > 0)
                    <tr><td>Balance given back{{ $return->refundMethod ? ' ('.$return->refundMethod->name.')' : '' }}</td><td class="num">{{ Money::format($return->refund_amount) }}</td></tr>
                @endif
            @else
                <tr><td>Store credit</td><td class="num">{{ Money::format($return->return_total) }}</td></tr>
            @endif
        @else
            <tr><td>Refunded via {{ $return->refundMethod?->name }}</td><td class="num">{{ Money::format($return->refund_amount) }}</td></tr>
            @if ($return->refund_reference)
                <tr class="small"><td>Ref no.</td><td class="num">{{ $return->refund_reference }}</td></tr>
            @endif
        @endif
    </table>

    <div style="margin-top:6mm" class="small">
        <div>Received by customer:</div>
        <div style="border-bottom:1px solid #000;height:8mm"></div>
    </div>

    <div class="tread"></div>
    <div class="center small">{!! nl2br(e($slip->footer_text)) !!}</div>
@endsection
