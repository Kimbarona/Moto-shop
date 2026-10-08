<?php

namespace App\Filament\Resources\SalesReturns;

use App\Enums\ReturnReason;
use App\Enums\ReturnResolution;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SalesReturns\Pages\ListSalesReturns;
use App\Filament\Resources\SalesReturns\Pages\ViewSalesReturn;
use App\Models\ReturnItem;
use App\Models\SalesReturn;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Returns & refunds, read-only (they are processed at the counter: /returns/new).
 */
class SalesReturnResource extends Resource
{
    protected static ?string $model = SalesReturn::class;

    protected static ?string $modelLabel = 'Return';

    protected static ?string $slug = 'returns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'return_no';

    public static function infolist(Schema $schema): Schema
    {
        $viewCost = fn () => auth()->user()->can('view-cost');

        return $schema
            ->columns(3)
            ->components([
                Section::make('Return')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('return_no')->label('Return no.')->weight('bold'),
                        TextEntry::make('date')->dateTime('M d, Y h:i A'),
                        TextEntry::make('order.slip_no')->label('Original slip')
                            ->url(fn (SalesReturn $record) => OrderResource::getUrl('view', ['record' => $record->order_id]))
                            ->color('primary'),
                        TextEntry::make('order.customer.name')->label('Customer')->placeholder('Walk-in'),
                        TextEntry::make('reason')->badge(),
                        TextEntry::make('resolution')->badge(),
                        TextEntry::make('remarks')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('processor.name')->label('Processed by'),
                        TextEntry::make('approver.name')->label('Approved by (PIN)'),
                    ]),
                Section::make('Money')
                    ->schema([
                        TextEntry::make('return_total')->label('Value returned')->money('PHP')->inlineLabel()->weight('bold'),
                        TextEntry::make('refund_amount')->label('Money given back')->money('PHP')->inlineLabel(),
                        TextEntry::make('refundMethod.name')->label('Method')->inlineLabel()->placeholder('—'),
                        TextEntry::make('refund_reference')->label('Ref no.')->inlineLabel()->placeholder('—'),
                        TextEntry::make('exchangeOrder.slip_no')->label('Credit used on')->inlineLabel()
                            ->placeholder(fn (SalesReturn $record) => $record->isExchange() ? 'Not used yet' : '—')
                            ->url(fn (SalesReturn $record) => $record->exchangeOrder ? OrderResource::getUrl('view', ['record' => $record->exchangeOrder]) : null),
                    ]),
                Section::make('Items returned')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(6)
                            ->schema([
                                TextEntry::make('orderItem.description')->label('Item')->columnSpan(2),
                                TextEntry::make('qty')->numeric(2),
                                TextEntry::make('disposition')->badge()->placeholder('Labor (refund only)'),
                                TextEntry::make('amount')->money('PHP'),
                                TextEntry::make('commission_reversed')->label('Commission reversed')->money('PHP')
                                    ->visible(fn (?ReturnItem $record) => $viewCost() && (float) $record?->commission_reversed > 0),
                                TextEntry::make('unit_cost')->label('Cost back')->money('PHP')
                                    ->visible(fn (?ReturnItem $record) => $viewCost() && $record?->disposition !== null),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.customer', 'refundMethod', 'processor', 'exchangeOrder']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('return_no')->label('Return')->searchable()->weight('bold'),
                TextColumn::make('date')->dateTime('M d, Y h:i A')->sortable(),
                TextColumn::make('order.slip_no')->label('Original slip')->searchable(),
                TextColumn::make('order.customer.name')->label('Customer')->placeholder('Walk-in')->toggleable(),
                TextColumn::make('reason')->badge(),
                TextColumn::make('resolution')->badge()
                    ->description(fn (SalesReturn $record) => $record->isExchange()
                        ? ($record->exchangeOrder ? 'used on '.$record->exchangeOrder->slip_no : 'credit not used')
                        : null),
                TextColumn::make('return_total')->label('Value')->money('PHP')->alignEnd()
                    ->summarize(Sum::make()->money('PHP')->label('')),
                TextColumn::make('refund_amount')->label('Money out')->money('PHP')->alignEnd()
                    ->description(fn (SalesReturn $record) => $record->refundMethod?->name)
                    ->summarize(Sum::make()->money('PHP')->label('')),
                TextColumn::make('processor.name')->label('By')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reason')->options(ReturnReason::options()),
                SelectFilter::make('resolution')->options(ReturnResolution::options()),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('date', '<=', $date))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesReturns::route('/'),
            'view' => ViewSalesReturn::route('/{record}'),
        ];
    }
}
