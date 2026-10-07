<?php

namespace App\Filament\Resources\StockReceivings\Pages;

use App\Filament\Resources\StockReceivings\StockReceivingResource;
use Filament\Resources\Pages\EditRecord;

class EditStockReceiving extends EditRecord
{
    protected static string $resource = StockReceivingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StockReceivingResource::postAction()
                // Post exactly what is on screen.
                ->before(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false))
                ->after(fn () => $this->redirect(StockReceivingResource::getUrl('view', ['record' => $this->getRecord()]))),
            StockReceivingResource::cancelAction()
                ->after(fn () => $this->redirect(StockReceivingResource::getUrl('index'))),
        ];
    }
}
