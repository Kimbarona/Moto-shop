<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Filament\Resources\StockCounts\StockCountResource;
use Filament\Resources\Pages\EditRecord;

class EditStockCount extends EditRecord
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StockCountResource::postAction()
                // Post exactly what is on screen.
                ->before(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false))
                ->after(fn () => $this->redirect(StockCountResource::getUrl('view', ['record' => $this->getRecord()]))),
            StockCountResource::cancelAction()
                ->after(fn () => $this->redirect(StockCountResource::getUrl('index'))),
        ];
    }
}
