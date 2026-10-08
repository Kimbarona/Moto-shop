<?php

namespace App\Filament\Resources\SalesReturns\Pages;

use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\SalesReturn;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewSalesReturn extends ViewRecord
{
    protected static string $resource = SalesReturnResource::class;

    protected function getHeaderActions(): array
    {
        /** @var SalesReturn $return */
        $return = $this->getRecord();

        return [
            Action::make('useCredit')
                ->label('Use credit on a new sale')
                ->icon(Heroicon::OutlinedArrowPath)
                ->url('/pos?exchange='.$return->id)
                ->visible($return->isExchange() && ! $return->exchangeOrder),
            Action::make('slip')
                ->label('Reprint return slip')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url('/print/return-slip/'.$return->id.'?reprint=1', shouldOpenInNewTab: true),
        ];
    }
}
