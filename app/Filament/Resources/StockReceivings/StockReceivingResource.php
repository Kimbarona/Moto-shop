<?php

namespace App\Filament\Resources\StockReceivings;

use App\Enums\DocumentStatus;
use App\Filament\Actions\InventoryDocumentActions;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockReceivings\Pages\CreateStockReceiving;
use App\Filament\Resources\StockReceivings\Pages\EditStockReceiving;
use App\Filament\Resources\StockReceivings\Pages\ListStockReceivings;
use App\Filament\Resources\StockReceivings\Pages\ViewStockReceiving;
use App\Models\Product;
use App\Models\StockReceiving;
use App\Services\Stock\ReceivingService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StockReceivingResource extends Resource
{
    protected static ?string $model = StockReceiving::class;

    protected static ?string $modelLabel = 'Stock In';

    protected static ?string $pluralModelLabel = 'Stock In';

    protected static ?string $slug = 'stock-in';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'receiving_no';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        DatePicker::make('date')->required()->default(now())->maxDate(now()),
                        Select::make('supplier_id')->relationship('supplier', 'name')->searchable()->preload()
                            ->createOptionForm([TextInput::make('name')->required()->maxLength(150)]),
                        TextInput::make('reference_no')->label('Supplier DR / invoice no.')->maxLength(100),
                        Textarea::make('notes')->rows(2)->columnSpanFull(),
                    ]),
                Section::make('Items received')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(6)
                            ->minItems(1)
                            ->addActionLabel('Add part')
                            ->live()
                            ->schema([
                                ProductResource::productSelect()
                                    ->columnSpan(3)
                                    ->distinct()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set) {
                                        // Prefill last average cost for roles allowed to see it.
                                        if ($state && auth()->user()->can('view-cost')) {
                                            $set('unit_cost', (float) Product::find($state)?->avg_cost);
                                        }
                                    }),
                                TextInput::make('qty')->numeric()->minValue(0.01)->required()->default(1)->live(onBlur: true),
                                TextInput::make('unit_cost')->label('Unit cost')->numeric()->prefix('₱')->minValue(0)->required()->live(onBlur: true),
                                TextEntry::make('line_total')->label('Line total')
                                    ->state(fn (Get $get) => Money::format((float) $get('qty') * (float) $get('unit_cost'))),
                            ]),
                        TextEntry::make('grand_total')->label('Total')
                            ->state(fn (Get $get) => Money::format(collect($get('items') ?? [])
                                ->sum(fn (array $item) => (float) ($item['qty'] ?? 0) * (float) ($item['unit_cost'] ?? 0)))),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'items', 'creator']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('receiving_no')->label('No.')->searchable()->weight('bold'),
                TextColumn::make('date')->date('M d, Y')->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable()->placeholder('—'),
                TextColumn::make('reference_no')->label('Ref no.')->searchable()->placeholder('—'),
                TextColumn::make('items_count')->counts('items')->label('Lines')->alignEnd(),
                TextColumn::make('total')->state(fn (StockReceiving $record) => $record->total())->money('PHP')->alignEnd(),
                TextColumn::make('status')->badge(),
                TextColumn::make('creator.name')->label('Encoded by')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('posted_at')->label('Posted')->dateTime('M d, Y h:i A')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(DocumentStatus::options()),
                SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (StockReceiving $record) => auth()->user()->can('update', $record)),
                ViewAction::make()->visible(fn (StockReceiving $record) => ! $record->isDraft()),
                static::postAction(),
            ]);
    }

    public static function postAction(): Action
    {
        return InventoryDocumentActions::post(
            fn (StockReceiving $record) => app(ReceivingService::class)->post($record, auth()->user()),
            fn (StockReceiving $record) => sprintf(
                'Add %d line(s) worth %s to stock and update average costs? Posted Stock In can no longer be edited.',
                $record->items()->count(),
                Money::format($record->total()),
            ),
        );
    }

    public static function cancelAction(): Action
    {
        return InventoryDocumentActions::cancel(fn (StockReceiving $record) => app(ReceivingService::class)->cancel($record, auth()->user()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockReceivings::route('/'),
            'create' => CreateStockReceiving::route('/create'),
            'view' => ViewStockReceiving::route('/{record}'),
            'edit' => EditStockReceiving::route('/{record}/edit'),
        ];
    }
}
