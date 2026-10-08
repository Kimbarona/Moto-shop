<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\Order;
use App\Models\OrderItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $viewCost = auth()->user()->can('view-cost');
        /** @var Order $order */
        $order = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['product', 'mechanic', 'package']))
            ->paginated(false)
            ->columns([
                TextColumn::make('description')->label('Item')->wrap()->weight('bold')
                    ->description(fn (OrderItem $record) => collect([
                        $record->isLabor() ? 'Labor'.($record->mechanic ? ' · '.$record->mechanic->name : '') : $record->product?->sku,
                        $record->package?->name,
                    ])->filter()->implode(' · ')),
                TextColumn::make('qty')->numeric(2)->alignEnd()
                    ->description(fn (OrderItem $record) => (float) $record->qty_returned > 0 ? rtrim(rtrim(number_format((float) $record->qty_returned, 2), '0'), '.').' returned' : null),
                TextColumn::make('unit_price')->label('Price')->money('PHP')->alignEnd()
                    ->description(fn (OrderItem $record) => (float) $record->unit_price != (float) $record->original_price ? 'SRP '.number_format((float) $record->original_price, 2) : null),
                TextColumn::make('discounts')->label('Discount')->alignEnd()
                    ->state(fn (OrderItem $record) => (float) $record->discount + (float) $record->discount_share)
                    ->money('PHP'),
                TextColumn::make('net')->label('Amount')->alignEnd()->weight('bold')
                    ->state(fn (OrderItem $record) => $record->netAmount())->money('PHP'),
                TextColumn::make('unit_cost')->label('Unit cost')->money('PHP')->alignEnd()->placeholder('—')->visible($viewCost),
                TextColumn::make('profit')->label('Profit')->alignEnd()->visible($viewCost && $order->isPaid())
                    ->state(fn (OrderItem $record) => round($record->netAmount() * $order->exVatRatio() - ($record->isPart() ? (float) $record->unit_cost * (float) $record->qty : 0), 2))
                    ->money('PHP'),
                TextColumn::make('commission_amount')->label('Commission')->money('PHP')->alignEnd()->visible($viewCost)
                    ->placeholder('—')->formatStateUsing(fn ($state) => (float) $state > 0 ? '₱'.number_format((float) $state, 2) : '—'),
            ]);
    }
}
