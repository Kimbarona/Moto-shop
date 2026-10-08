<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DefectiveStocks\DefectiveStockResource;
use App\Models\DefectiveStock;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class DefectiveAwaiting extends TableWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 3];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Defective stock awaiting action')
            ->query(fn () => DefectiveStock::query()->pending()->with('product')->oldest())
            ->paginated([5])
            ->columns([
                TextColumn::make('product.name')->label('Part')->wrap(),
                TextColumn::make('qty')->numeric(2)->alignEnd(),
                TextColumn::make('source')
                    ->state(fn (DefectiveStock $record) => $record->return_item_id ? 'Customer return' : 'From shelf')
                    ->badge()->color('gray'),
                TextColumn::make('created_at')->label('Since')->since(),
            ])
            ->headerActions([
                Action::make('open')->label('Resolve')->link()
                    ->url(DefectiveStockResource::getUrl()),
            ])
            ->emptyStateHeading('Nothing to resolve')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
