<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\MovementType;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The stock ledger (append-only, read-only).
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table, bool $withProduct = true): Table
    {
        $canViewCost = fn () => auth()->user()?->can('view-cost') ?? false;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'user', 'reference']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns(array_values(array_filter([
                TextColumn::make('created_at')->label('Date')->dateTime('M d, Y h:i A')->sortable(),
                $withProduct ? TextColumn::make('product.name')->label('Part')->searchable()->wrap()
                    ->description(fn (StockMovement $record) => $record->product?->sku) : null,
                TextColumn::make('type')->badge(),
                TextColumn::make('qty')->numeric(2)->alignEnd()
                    ->color(fn (StockMovement $record) => (float) $record->qty < 0 ? 'danger' : 'success')
                    ->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '').number_format((float) $state, 2)),
                TextColumn::make('balance_after')->label('Balance')->numeric(2)->alignEnd()->weight('bold'),
                TextColumn::make('unit_cost')->label('Unit cost')->money('PHP')->alignEnd()->visible($canViewCost),
                TextColumn::make('reference')->label('Reference')
                    ->state(fn (StockMovement $record) => $record->referenceLabel())->placeholder('—'),
                TextColumn::make('remarks')->limit(40)->wrap()->toggleable()->searchable(),
                TextColumn::make('user.name')->label('By')->toggleable(),
            ])))
            ->filters([
                SelectFilter::make('type')->options(MovementType::options())->multiple(),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => collect([
                        filled($data['from'] ?? null) ? 'From '.$data['from'] : null,
                        filled($data['until'] ?? null) ? 'Until '.$data['until'] : null,
                    ])->filter()->values()->all()),
            ])
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
