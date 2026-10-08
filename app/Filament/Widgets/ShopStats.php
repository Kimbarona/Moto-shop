<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Models\DefectiveStock;
use App\Models\Order;
use App\Models\Product;
use App\Reports\Period;
use App\Services\Reports\SalesLedger;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Headline numbers. Sales figures for admins (profit and inventory value only with
 * "view-cost"); stock figures for everyone who can open the back-office.
 */
class ShopStats extends StatsOverviewWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return auth()->user()?->can('view-reports') ? 4 : 3;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        if ($user->can('view-reports')) {
            $s = app(SalesLedger::class)->summary($this->period());
            $when = $this->periodLabel();
            $pending = Order::query()->pending();
            $daily = collect(app(SalesLedger::class)->daily(new Period(now()->subDays(6), now())))->pluck('net')->all();

            $stats[] = Stat::make('Sales '.$when, Money::format($s['sales']))
                ->description($s['discounts'] > 0 ? 'after '.Money::format($s['discounts']).' discounts' : 'no discounts given')
                ->icon(Heroicon::OutlinedBanknotes)
                ->chart($daily)
                ->color('primary');
            $stats[] = Stat::make('Net sales after refunds', Money::format($s['net']))
                ->description($s['refunds'] > 0 ? Money::format($s['refunds']).' refunded' : 'no refunds')
                ->descriptionColor($s['refunds'] > 0 ? 'purple' : 'gray')
                ->icon(Heroicon::OutlinedReceiptRefund);

            if ($user->can('view-cost')) {
                $stats[] = Stat::make('Gross profit', Money::format($s['gross_profit']))
                    ->description($s['margin'] !== null ? number_format($s['margin'], 1).'% margin' : '—')
                    ->color($s['gross_profit'] >= 0 ? 'success' : 'danger')
                    ->icon(Heroicon::OutlinedArrowTrendingUp);
            }

            $stats[] = Stat::make('Orders paid', number_format($s['orders']))
                ->description($s['orders'] > 0 ? 'avg '.Money::format($s['sales'] / $s['orders']) : $when)
                ->icon(Heroicon::OutlinedCheckBadge);
            $stats[] = Stat::make('Pending orders', number_format((clone $pending)->count()))
                ->description(Money::format((float) (clone $pending)->sum('total')).' waiting to be paid')
                ->color('warning')
                ->icon(Heroicon::OutlinedClock);
        }

        $low = Product::query()->active()->lowStock()->count();
        $stats[] = Stat::make('Low stock', number_format($low))
            ->description($low > 0 ? 'parts at or below reorder level' : 'all parts above reorder level')
            ->color($low > 0 ? 'danger' : 'success')
            ->icon(Heroicon::OutlinedExclamationCircle);

        if ($user->can('view-cost')) {
            $value = (float) DB::table('products')->whereNull('deleted_at')->where('qty_on_hand', '>', 0)->sum(DB::raw('qty_on_hand * avg_cost'));
            $stats[] = Stat::make('Inventory value', Money::format($value))
                ->description('on hand at average cost')
                ->icon(Heroicon::OutlinedCube);
        }

        if (! $user->can('view-reports')) {
            $stats[] = Stat::make('Defective awaiting action', number_format(DefectiveStock::pending()->count()))
                ->icon(Heroicon::OutlinedExclamationTriangle);
            $stats[] = Stat::make('Active parts', number_format(Product::query()->active()->count()))
                ->icon(Heroicon::OutlinedCube);
        }

        return $stats;
    }
}
