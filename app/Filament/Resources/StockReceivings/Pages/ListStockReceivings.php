<?php

namespace App\Filament\Resources\StockReceivings\Pages;

use App\Filament\Resources\StockReceivings\StockReceivingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockReceivings extends ListRecords
{
    protected static string $resource = StockReceivingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
