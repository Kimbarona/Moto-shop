<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Exports\StockMovementsExport;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Stock card: full movement history of one part with the running balance.
 */
class StockCardRelationManager extends RelationManager
{
    protected static string $relationship = 'stockMovements';

    protected static ?string $title = 'Stock card';

    public function table(Table $table): Table
    {
        return StockMovementResource::table($table, withProduct: false)
            ->headerActions([
                Action::make('export')
                    ->label('Export')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => Excel::download(
                        new StockMovementsExport($this->getFilteredSortedTableQuery(), auth()->user()->can('view-cost')),
                        'stock-card-'.$this->getOwnerRecord()->sku.'-'.now()->format('Ymd').'.xlsx',
                    )),
            ]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('manage-inventory');
    }
}
