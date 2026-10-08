<?php

namespace App\Filament\Resources\Products\Pages;

use App\Exports\ProductsExport;
use App\Filament\Resources\Products\ProductResource;
use App\Imports\HeadingRowImport;
use App\Models\Product;
use App\Services\Catalog\ProductImportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ActionGroup::make([
                Action::make('export')
                    ->label('Export to Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn () => Excel::download(
                        new ProductsExport(auth()->user()->can('view-cost'), $this->getFilteredSortedTableQuery()),
                        'products-'.now()->format('Ymd-His').'.xlsx',
                    )),
                Action::make('template')
                    ->label('Download import template')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->action(fn () => Excel::download(new ProductsExport(false, template: true), 'products-import-template.xlsx')),
                Action::make('import')
                    ->label('Import from Excel')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->visible(fn () => auth()->user()->can('create', Product::class))
                    ->modalDescription('Rows match on SKU: an existing SKU is updated, a blank SKU adds a new part. Stock quantities are not imported — use Stock In. Tip: export first, edit, then import.')
                    ->schema([
                        FileUpload::make('file')
                            ->label('Excel or CSV file')
                            ->disk('local')
                            ->directory('imports')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                            ])
                            ->maxSize(5120)
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $path = Storage::disk('local')->path($data['file']);

                        try {
                            $rows = Excel::toCollection(new HeadingRowImport, $path)->first() ?? collect();
                            $result = app(ProductImportService::class)->import($rows, auth()->user());
                        } finally {
                            Storage::disk('local')->delete($data['file']);
                        }

                        activity('Product')->causedBy(auth()->user())->event('imported')
                            ->withProperties(collect($result)->except('errors')->all() + ['errors' => count($result['errors'])])
                            ->log("Imported products: {$result['created']} new, {$result['updated']} updated");

                        Notification::make()
                            ->title("Import done: {$result['created']} added, {$result['updated']} updated".($result['skipped'] ? ", {$result['skipped']} skipped" : ''))
                            ->body(collect($result['errors'])->take(8)->implode("\n").(count($result['errors']) > 8 ? "\n…and ".(count($result['errors']) - 8).' more' : ''))
                            ->{$result['skipped'] ? 'warning' : 'success'}()
                            ->persistent()
                            ->send();
                    }),
            ])
                ->label('Import / export')
                ->icon(Heroicon::OutlinedTableCells)
                ->button()
                ->color('gray'),
        ];
    }
}
