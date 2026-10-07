<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use Filament\Resources\Pages\EditRecord;

class EditStockAdjustment extends EditRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StockAdjustmentResource::postAction()
                // Post exactly what is on screen.
                ->before(fn () => $this->save(shouldRedirect: false, shouldSendSavedNotification: false))
                ->after(fn () => $this->redirect(StockAdjustmentResource::getUrl('view', ['record' => $this->getRecord()]))),
            StockAdjustmentResource::cancelAction()
                ->after(fn () => $this->redirect(StockAdjustmentResource::getUrl('index'))),
        ];
    }
}
