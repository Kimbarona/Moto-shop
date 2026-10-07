<?php

namespace App\Filament\Resources\StockCounts\RelationManagers;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\Stock\PhysicalCountService;
use App\Support\Money;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Count sheet. While the count is open, "System" shows LIVE on-hand qty (that is what
 * posting compares against); after posting it shows the qty at posting time.
 */
class CountItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Count sheet';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        /** @var StockCount $count */
        $count = $this->getOwnerRecord();
        $open = $count->isDraft();
        $canViewCost = auth()->user()->can('view-cost');

        $system = fn (StockCountItem $item) => $open ? (float) $item->product->qty_on_hand : (float) $item->system_qty;
        $variance = fn (StockCountItem $item) => $item->counted_qty === null ? null : round((float) $item->counted_qty - $system($item), 2);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('product.unit'))
            ->defaultSort(fn (Builder $query) => $query->orderBy(
                Product::select('name')->whereColumn('products.id', 'stock_count_items.product_id'),
            ))
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('product.sku')->label('SKU')->badge()->color('gray')->searchable(),
                TextColumn::make('product.name')->label('Part')->searchable()->wrap()
                    ->description(fn (StockCountItem $item) => $item->product->bin_location ? 'Bin '.$item->product->bin_location : null),
                TextColumn::make('system')->label($open ? 'System (live)' : 'System')
                    ->state($system)->numeric(2)->alignEnd()
                    ->suffix(fn (StockCountItem $item) => ' '.$item->product->unit?->abbreviation),
                $open
                    ? TextInputColumn::make('counted_qty')->label('Counted')
                        ->type('number')->rules(['nullable', 'numeric', 'min:0'])
                        ->extraInputAttributes(['step' => '0.01', 'min' => '0', 'class' => 'text-end'])
                        ->updateStateUsing(function (StockCountItem $record, $state) {
                            try {
                                app(PhysicalCountService::class)->recordCount($record, $state);
                            } catch (ValidationException $e) {
                                Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                            }

                            return $record->fresh()->counted_qty;
                        })
                    : TextColumn::make('counted_qty')->label('Counted')->numeric(2)->alignEnd()->placeholder('Not counted'),
                TextColumn::make('variance')->label('Variance')
                    ->state($variance)
                    ->numeric(2)->alignEnd()->placeholder('—')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state < 0 => 'danger',
                        $state > 0 => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('variance_value')->label('Variance value')
                    ->visible($canViewCost)
                    ->state(fn (StockCountItem $item) => ($v = $variance($item)) === null ? null : Money::format($v * (float) ($open ? $item->product->avg_cost : $item->unit_cost)))
                    ->alignEnd()->placeholder('—'),
            ])
            ->filters([
                Filter::make('uncounted')->label('Not yet counted')->query(fn (Builder $query) => $query->whereNull('counted_qty')),
                Filter::make('counted')->label('Counted')->query(fn (Builder $query) => $query->whereNotNull('counted_qty')),
            ])
            ->headerActions([])
            ->recordActions([]);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can('view', $ownerRecord);
    }
}
