<?php

namespace App\Filament\Resources\StockAdjustments\Pages;

use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Saves a draft; stock only changes when the draft is posted from the edit page.
 */
class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
