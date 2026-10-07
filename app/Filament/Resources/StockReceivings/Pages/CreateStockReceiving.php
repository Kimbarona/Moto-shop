<?php

namespace App\Filament\Resources\StockReceivings\Pages;

use App\Filament\Resources\StockReceivings\StockReceivingResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Saves a draft; stock only changes when the draft is posted from the edit page.
 */
class CreateStockReceiving extends CreateRecord
{
    protected static string $resource = StockReceivingResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
