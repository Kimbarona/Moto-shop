@php
    /** @var string $paper */
    $widths = ['58mm' => '48mm', '80mm' => '72mm', 'a4' => '186mm', 'half_letter' => '128mm'];
    $pageSizes = ['58mm' => '58mm auto', '80mm' => '80mm auto', 'a4' => 'A4', 'half_letter' => '5.5in 8.5in'];
    $thermal = in_array($paper, ['58mm', '80mm'], true);
    $logo = $shop->logo && $slip->show_logo ? asset('storage/'.$shop->logo) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        @page { size: {{ $pageSizes[$paper] ?? '80mm auto' }}; margin: {{ $thermal ? '2mm 0' : '10mm' }}; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body {
            font-family: 'Barlow', 'Arial Narrow', Arial, sans-serif;
            font-size: {{ $paper === '58mm' ? '10px' : ($thermal ? '11.5px' : '12.5px') }};
            line-height: 1.3;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .doc { width: {{ $widths[$paper] ?? '72mm' }}; margin: 0 auto; padding: 2mm 0; }
        .doc + .doc { page-break-before: always; break-before: page; }
        .center { text-align: center; }
        .right { text-align: right; }
        .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .muted { color: #444; }
        .small { font-size: 0.85em; }
        .bold { font-weight: 700; }
        h1, .h { font-family: 'Barlow Condensed', 'Arial Narrow', Arial, sans-serif; text-transform: uppercase; letter-spacing: .04em; margin: 0; }
        h1 { font-size: 1.5em; }
        .title { font-size: 1.25em; font-weight: 700; margin: 1.5mm 0; }
        .logo { max-height: 16mm; max-width: 60%; display: block; margin: 0 auto 1mm; filter: grayscale(1); }
        /* Tire-tread / checkered divider */
        .tread { height: 3mm; margin: 2mm 0; background:
            repeating-linear-gradient(90deg, #000 0 2mm, transparent 2mm 4mm) top / 100% 50% no-repeat,
            repeating-linear-gradient(90deg, transparent 0 2mm, #000 2mm 4mm) bottom / 100% 50% no-repeat; }
        .rule { border-top: 1px dashed #000; margin: 1.5mm 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: .4mm 0; vertical-align: top; }
        th { text-align: left; font-weight: 700; border-bottom: 1px solid #000; }
        .meta td:first-child { width: 38%; color: #333; }
        .totals td { padding: .3mm 0; }
        .grand td { font-size: 1.3em; font-weight: 800; border-top: 1px solid #000; padding-top: 1mm; }
        .badge { display: inline-block; border: 2px solid #000; padding: .5mm 2mm; font-weight: 800; letter-spacing: .1em; }
        .stamp { border: 3px double #000; text-align: center; font-size: 1.4em; font-weight: 800; padding: 1mm; margin: 2mm 0; }
        .sign { margin-top: 10mm; display: flex; gap: 6mm; }
        .sign div { flex: 1; border-top: 1px solid #000; text-align: center; padding-top: 1mm; font-size: .85em; }
        .toolbar { font-family: system-ui, sans-serif; text-align: center; padding: 10px; background: #16181D; color: #fff; }
        .toolbar button { background: #FF6B1A; color: #fff; border: 0; padding: 8px 18px; font-weight: 700; border-radius: 6px; cursor: pointer; margin: 0 4px; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    @for ($copy = 1; $copy <= $copies; $copy++)
        <div class="doc">
            <div class="center">
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="">
                @endif
                <h1>{{ $shop->shop_name }}</h1>
                @if ($shop->address)<div class="small">{{ $shop->address }}</div>@endif
                @if ($shop->mobile || $shop->email)<div class="small">{{ collect([$shop->mobile, $shop->email])->filter()->implode(' · ') }}</div>@endif
                @if ($shop->tin)<div class="small">TIN {{ $shop->tin }}</div>@endif
                @if ($slip->header_text)<div class="small" style="margin-top:1mm">{!! nl2br(e($slip->header_text)) !!}</div>@endif
            </div>
            <div class="tread"></div>

            @yield('body')

            @if ($copies > 1)
                <div class="center small muted" style="margin-top:2mm">Copy {{ $copy }} of {{ $copies }}</div>
            @endif
        </div>
    @endfor

    @if ($autoPrint)
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 150);
            });
            window.addEventListener('afterprint', function () {
                if (window.opener) { window.close(); }
            });
        </script>
    @endif
</body>
</html>
