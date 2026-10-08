<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Models\Product;
use App\Services\Reports\SalesLedger;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopSellingParts extends TableWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 3];

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function table(Table $table): Table
    {
        $sold = app(SalesLedger::class)->saleLines($this->period())
            ->where('order_items.line_type', 'part')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.qty) as qty, SUM('.SalesLedger::PAID.') as sales')
            ->orderByDesc('qty')
            ->limit(10)
            ->get()
            ->keyBy('product_id');

        $ids = $sold->keys()->all();

        return $table
            ->heading('Top-selling parts '.$this->periodLabel())
            ->query(fn (): Builder => Product::withTrashed()
                ->whereIn('id', $ids === [] ? [0] : $ids)
                ->when($ids !== [], fn (Builder $q) => $q->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $ids)).')')))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Part')->wrap()->description(fn (Product $record) => $record->sku),
                TextColumn::make('sold_qty')->label('Sold')->alignEnd()
                    ->state(fn (Product $record) => rtrim(rtrim(number_format((float) ($sold[$record->id]->qty ?? 0), 2), '0'), '.')),
                TextColumn::make('sold_amount')->label('Sales')->alignEnd()->money('PHP')
                    ->state(fn (Product $record) => (float) ($sold[$record->id]->sales ?? 0)),
                TextColumn::make('qty_on_hand')->label('On hand')->numeric(2)->alignEnd(),
            ])
            ->emptyStateHeading('No parts sold '.$this->periodLabel());
    }
}
