@extends('print.layout')

@php
    /** @var \App\Models\Order $order */
    $qty = fn ($q) => rtrim(rtrim(number_format((float) $q, 2), '0'), '.');
@endphp

@section('title', 'Job Ticket '.$order->order_no)

@section('body')
    <div class="center">
        <div class="title h">Service Order / Job Ticket</div>
        @if ($reprint)<div class="badge">REPRINT</div>@endif
    </div>

    <table class="meta">
        <tr><td>Order no.</td><td class="bold">{{ $order->order_no }}</td></tr>
        <tr><td>Date in</td><td>{{ $order->created_at?->format('M d, Y h:i A') }}</td></tr>
        <tr><td>Customer</td><td>{{ $order->customer?->name }}</td></tr>
        @if ($order->customer?->mobile)
            <tr><td>Mobile</td><td>{{ $order->customer->mobile }}</td></tr>
        @endif
        <tr><td>Plate no.</td><td class="bold">{{ $order->motorcycle?->plate_no ?? 'No plate' }}</td></tr>
        <tr><td>Model</td><td>{{ $order->motorcycle?->motorcycleModel?->full_name ?? '—' }}{{ $order->motorcycle?->color ? ' · '.$order->motorcycle->color : '' }}</td></tr>
        <tr><td>Odometer in</td><td>{{ $order->odometer_in ? number_format($order->odometer_in).' km' : '—' }}</td></tr>
        <tr><td>Mechanic</td><td>{{ $order->mechanics->pluck('name')->implode(', ') ?: 'Unassigned' }}</td></tr>
        <tr><td>Received by</td><td>{{ $order->creator?->name }}</td></tr>
    </table>

    <div class="rule"></div>
    <div class="bold">Complaint / request</div>
    <div style="min-height: 10mm">{!! nl2br(e($order->complaint ?: '—')) !!}</div>

    <div class="rule"></div>
    <table>
        <thead>
            <tr><th>Parts & labor</th><th class="num">Qty</th></tr>
        </thead>
        <tbody>
            @forelse ($order->items as $item)
                <tr>
                    <td>
                        {{ $item->description }}
                        @if ($item->isLabor() && $item->mechanic)
                            <span class="small muted">({{ $item->mechanic->name }})</span>
                        @endif
                    </td>
                    <td class="num">{{ $qty($item->qty) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="muted">To be added by the mechanic.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="rule"></div>
    <div class="bold">Mechanic notes</div>
    <div style="min-height: 14mm; border-bottom: 1px dotted #000"></div>

    <div class="sign">
        <div>Customer</div>
        <div>Mechanic</div>
    </div>
@endsection
