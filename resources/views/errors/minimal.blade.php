@php
    $shopName = rescue(fn () => app(\App\Settings\ShopSettings::class)->shop_name, config('app.name'), report: false);
    $hint = trim($__env->yieldContent('hint'));
    $action = trim($__env->yieldContent('action_url')) ?: url('/');
    $actionLabel = trim($__env->yieldContent('action_label')) ?: 'Back to start';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $shopName }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        /* Standalone "Pit Lane" error page: no Vite assets, so it renders even when the build is missing. */
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100svh; display: grid; place-items: center; padding: 24px;
            font-family: 'Barlow', ui-sans-serif, system-ui, 'Segoe UI', sans-serif;
            color: #e2e8f0; background-color: #16181d;
            background-image:
                repeating-linear-gradient(45deg, rgb(255 255 255 / 0.03) 0 2px, transparent 2px 10px),
                repeating-linear-gradient(-45deg, rgb(255 255 255 / 0.025) 0 2px, transparent 2px 10px);
        }
        main { width: 100%; max-width: 30rem; text-align: center; }
        .brand { display: inline-flex; align-items: center; gap: 10px; color: #fff; text-decoration: none;
            font: 800 20px/1 'Barlow Condensed', 'Arial Narrow', sans-serif; letter-spacing: .06em; text-transform: uppercase; }
        .brand span { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 7px; background: #ff6b1a; }
        .code { margin: 40px 0 4px; font: 800 120px/0.9 'Barlow Condensed', 'Arial Narrow', sans-serif; color: #ff6b1a;
            font-variant-numeric: tabular-nums; letter-spacing: .02em; }
        .flag { width: 160px; height: 12px; margin: 18px auto 22px; opacity: .3;
            background-image: repeating-conic-gradient(#fff 0 25%, transparent 0 50%); background-size: 12px 12px; }
        h1 { margin: 0 0 10px; font: 800 30px/1.1 'Barlow Condensed', 'Arial Narrow', sans-serif; letter-spacing: .04em; text-transform: uppercase; color: #fff; }
        p { margin: 0 auto 28px; max-width: 26rem; color: #a1a8b5; font-size: 16px; line-height: 1.5; }
        .btn { display: inline-block; padding: 14px 26px; border-radius: 8px; text-decoration: none; color: #16181d; background-color: #ff6b1a;
            background-image: repeating-linear-gradient(-60deg, transparent 0 14px, rgb(255 255 255 / .18) 14px 17px);
            font: 800 18px/1 'Barlow Condensed', 'Arial Narrow', sans-serif; letter-spacing: .06em; text-transform: uppercase; }
        .btn:focus-visible { outline: 3px solid #ffc93c; outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        <a class="brand" href="{{ url('/') }}">
            <span aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#16181d" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </span>
            {{ $shopName }}
        </a>
        <div class="code">@yield('code')</div>
        <div class="flag" aria-hidden="true"></div>
        <h1>@yield('message')</h1>
        @if ($hint !== '')
            {{-- Inline @section values are already escaped by Blade. --}}
            <p>{!! $hint !!}</p>
        @endif
        <a class="btn" href="{{ $action }}">{{ $actionLabel }}</a>
    </main>
</body>
</html>
