<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Exports\StockMovementsExport;
use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Maatwebsite\Excel\Facades\Excel;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export to Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => Excel::download(
                    new StockMovementsExport($this->getFilteredSortedTableQuery(), auth()->user()->can('view-cost')),
                    'stock-movements-'.now()->format('Ymd-His').'.xlsx',
                )),
        ];
    }
}
