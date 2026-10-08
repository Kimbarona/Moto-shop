@php
    /** @var \App\Reports\ReportResult $result */
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $result->title }}</title>
    <style>
        @page { margin: 14mm 10mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #16181d; }
        h1 { font-size: 15pt; margin: 0; letter-spacing: .5px; text-transform: uppercase; }
        h2 { font-size: 10.5pt; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: .5px; }
        .meta { color: #555; margin: 2px 0 8px; }
        .stripe { height: 4px; background: #ff6b1a; margin: 6px 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #2a2f38; color: #fff; text-align: left; padding: 4px 5px; font-weight: bold; }
        td { padding: 3px 5px; border-bottom: 0.5px solid #ddd; vertical-align: top; }
        tr:nth-child(even) td { background: #f6f7f9; }
        .num { text-align: right; white-space: nowrap; }
        .neg { color: #b91c1c; }
        tfoot td { font-weight: bold; border-top: 1.5px solid #16181d; background: #fff !important; }
        .figures { width: auto; margin-bottom: 6px; }
        .figures td { border: 1px solid #ddd; padding: 4px 8px; background: #fff !important; }
        .figures .label { color: #555; font-size: 7.5pt; }
        .figures .value { font-weight: bold; font-size: 10pt; }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; color: #888; font-size: 7pt; }
        .empty { color: #888; font-style: italic; padding: 6px 0; }
    </style>
</head>
<body>
    <div class="footer">{{ $shop }} · {{ $result->title }} · printed {{ now()->format('M d, Y h:i A') }} by {{ $user }}</div>

    <h1>{{ $result->title }}</h1>
    <div class="meta">
        {{ $shop }} · {{ $result->period }}
        @foreach ($result->options as $label => $value) · {{ $label }}: {{ $value }} @endforeach
    </div>
    <div class="stripe"></div>

    @if ($result->summary)
        <table class="figures"><tr>
            @foreach ($result->summary as $label => $figure)
                <td><div class="label">{{ $label }}</div><div class="value">{{ $result->formatFigure($figure) }}</div></td>
            @endforeach
        </tr></table>
    @endif

    @foreach ($result->sections as $section)
        @if ($section->title)<h2>{{ $section->title }}</h2>@endif
        @if ($section->rows === [])
            <div class="empty">Nothing in this period.</div>
        @else
            <table>
                <thead><tr>
                    @foreach ($section->columns as $column)
                        <th class="{{ $column->isNumeric() ? 'num' : '' }}">{{ $column->label }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach ($section->rows as $row)
                        <tr>
                            @foreach ($section->columns as $column)
                                @php $value = $row[$column->key] ?? null; @endphp
                                <td class="{{ $column->isNumeric() ? 'num' : '' }} {{ $column->isNumeric() && (float) $value < 0 ? 'neg' : '' }}">{{ $column->format($value) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if ($totals = $section->totals())
                    <tfoot><tr>
                        @foreach ($section->columns as $i => $column)
                            <td class="{{ $column->isNumeric() ? 'num' : '' }}">{{ $i === 0 ? 'TOTAL' : (array_key_exists($column->key, $totals) ? $column->format($totals[$column->key]) : '') }}</td>
                        @endforeach
                    </tr></tfoot>
                @endif
            </table>
        @endif
    @endforeach
</body>
</html>
