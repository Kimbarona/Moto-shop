<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockList extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 3];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Low stock')
            ->query(fn () => Product::query()->active()->withStockLevels()->with('unit')->lowStock()
                ->orderByRaw('products.qty_on_hand - products.reorder_level'))
            ->paginated([5])
            ->columns([
                TextColumn::make('name')->label('Part')->wrap()->description(fn (Product $record) => $record->sku),
                TextColumn::make('qty_on_hand')->label('On hand')->numeric(2)->alignEnd()
                    ->color(fn (Product $record) => (float) $record->qty_on_hand <= 0 ? 'danger' : 'warning')->weight('bold'),
                TextColumn::make('available_qty')->label('Available')->numeric(2)->alignEnd(),
                TextColumn::make('reorder_level')->label('Reorder at')->numeric(2)->alignEnd(),
            ])
            ->recordUrl(fn (Product $record) => ProductResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Nothing is low')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
