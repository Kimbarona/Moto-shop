<?php

namespace App\Filament\Resources\DefectiveStocks;

use App\Enums\DefectiveStatus;
use App\Filament\Actions\InventoryDocumentActions;
use App\Filament\Resources\DefectiveStocks\Pages\ManageDefectiveStocks;
use App\Models\DefectiveStock;
use App\Models\Supplier;
use App\Services\Stock\DefectiveStockService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Defective items kept out of sellable stock until returned to the supplier or written off.
 */
class DefectiveStockResource extends Resource
{
    protected static ?string $model = DefectiveStock::class;

    protected static ?string $modelLabel = 'Defective Item';

    protected static ?string $pluralModelLabel = 'Defective Stock';

    protected static ?string $slug = 'defective-stock';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Defective Stock';

    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $pending = DefectiveStock::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $canViewCost = fn () => auth()->user()?->can('view-cost') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.unit', 'supplier', 'resolver']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('M d, Y h:i A')->sortable(),
                TextColumn::make('product.name')->label('Part')->searchable()->wrap()
                    ->description(fn (DefectiveStock $record) => $record->product?->sku),
                TextColumn::make('qty')->numeric(2)->alignEnd()
                    ->suffix(fn (DefectiveStock $record) => ' '.$record->product?->unit?->abbreviation),
                TextColumn::make('source')
                    ->state(fn (DefectiveStock $record) => $record->return_item_id ? 'Customer return' : 'Pulled from shelf')
                    ->badge()->color('gray'),
                TextColumn::make('value')->state(fn (DefectiveStock $record) => $record->value())->money('PHP')->alignEnd()
                    ->visible($canViewCost),
                TextColumn::make('status')->badge(),
                TextColumn::make('supplier.name')->label('Returned to')->placeholder('—')
                    ->description(fn (DefectiveStock $record) => $record->supplier_reference ? 'Ref '.$record->supplier_reference : null),
                TextColumn::make('notes')->limit(40)->wrap()->toggleable(),
                TextColumn::make('resolved_at')->label('Resolved')->dateTime('M d, Y')->placeholder('—')
                    ->description(fn (DefectiveStock $record) => $record->resolver?->name)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(DefectiveStatus::options())->default(DefectiveStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('returnToSupplier')
                    ->label('Return to supplier')
                    ->icon(Heroicon::OutlinedTruck)
                    ->color('info')
                    ->visible(fn (DefectiveStock $record) => auth()->user()->can('resolve', $record))
                    ->schema([
                        Select::make('supplier_id')->label('Supplier')
                            ->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
                            ->searchable()->required(),
                        TextInput::make('reference')->label('Reference no. (RMA / DR)')->maxLength(100),
                        Textarea::make('notes')->rows(2),
                    ])
                    ->action(fn (DefectiveStock $record, array $data, Action $action) => InventoryDocumentActions::run(
                        fn () => app(DefectiveStockService::class)->returnToSupplier($record, (int) $data['supplier_id'], $data['reference'] ?? null, auth()->user(), $data['notes'] ?? null),
                        $action,
                        'Marked as returned to supplier',
                    )),
                Action::make('writeOff')
                    ->label('Write off')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (DefectiveStock $record) => auth()->user()->can('resolve', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The item is disposed of. This cannot be undone.')
                    ->schema([Textarea::make('notes')->label('Reason')->rows(2)->required()])
                    ->action(fn (DefectiveStock $record, array $data, Action $action) => InventoryDocumentActions::run(
                        fn () => app(DefectiveStockService::class)->writeOff($record, auth()->user(), $data['notes']),
                        $action,
                        'Written off',
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDefectiveStocks::route('/'),
        ];
    }
}
