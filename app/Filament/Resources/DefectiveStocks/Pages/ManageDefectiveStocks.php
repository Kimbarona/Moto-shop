<?php

namespace App\Filament\Resources\DefectiveStocks\Pages;

use App\Filament\Actions\InventoryDocumentActions;
use App\Filament\Resources\DefectiveStocks\DefectiveStockResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\DefectiveStock;
use App\Models\Product;
use App\Services\Stock\DefectiveStockService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageDefectiveStocks extends ManageRecords
{
    protected static string $resource = DefectiveStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Customer returns land here automatically (Returns, phase 5); this is for shelf finds.
            Action::make('fromShelf')
                ->label('Pull defective part from shelf')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->visible(fn () => auth()->user()->can('create', DefectiveStock::class))
                ->schema([
                    ProductResource::productSelect(),
                    TextInput::make('qty')->numeric()->minValue(0.01)->default(1)->required(),
                    Textarea::make('notes')->label('Defect')->rows(2)->required(),
                ])
                ->action(fn (array $data, Action $action) => InventoryDocumentActions::run(
                    fn () => app(DefectiveStockService::class)->fromStock(
                        Product::findOrFail($data['product_id']),
                        (float) $data['qty'],
                        $data['notes'],
                        auth()->user(),
                    ),
                    $action,
                    'Moved to defective stock',
                    'Sellable stock was reduced.',
                )),
        ];
    }
}
