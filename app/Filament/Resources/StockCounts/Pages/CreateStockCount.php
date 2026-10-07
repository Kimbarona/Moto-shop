<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Filament\Concerns\CallsServices;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Services\Stock\PhysicalCountService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Opening a count snapshots every active part in scope onto the count sheet.
 */
class CreateStockCount extends CreateRecord
{
    use CallsServices;

    protected static string $resource = StockCountResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->callService(fn () => app(PhysicalCountService::class)->open($data, auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
