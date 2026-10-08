<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\RefundStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\ReturnsRelationManager;
use App\Models\Order;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
 * Every order, read-only. Orders are made and paid at the counter (POS); here admins
 * look things up, reprint, void (same day) or start a return.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'order_no';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['order_no', 'slip_no', 'customer.name', 'motorcycle.plate_no'];
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return $record->slip_no ? "{$record->slip_no} ({$record->order_no})" : $record->order_no;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Order')
                    ->columnSpan(2)
                    ->columns(3)
                    ->schema([
                        TextEntry::make('order_no')->label('Order no.')->weight('bold'),
                        TextEntry::make('slip_no')->label('Slip no.')->placeholder('Not paid')->weight('bold'),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('service_status')->label('Service')->badge()->placeholder('—')
                            ->visible(fn (Order $record) => $record->isService()),
                        TextEntry::make('refund_status')->label('Refunds')->badge()
                            ->visible(fn (Order $record) => $record->refund_status !== RefundStatus::None),
                        TextEntry::make('customer.name')->label('Customer')->placeholder('Walk-in'),
                        TextEntry::make('motorcycle.plate_no')->label('Motorcycle')->placeholder('—')
                            ->formatStateUsing(fn ($state, Order $record) => trim($state.' · '.$record->motorcycle?->motorcycleModel?->full_name, ' ·')),
                        TextEntry::make('odometer_in')->label('Odometer')->suffix(' km')->numeric()->placeholder('—'),
                        TextEntry::make('mechanics.name')->label('Mechanic')->placeholder('—'),
                        TextEntry::make('complaint')->columnSpanFull()->placeholder('—')
                            ->visible(fn (Order $record) => $record->isService()),
                        TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('void_reason')->label('Void reason')->color('danger')->columnSpanFull()
                            ->visible(fn (Order $record) => $record->isVoided())
                            ->formatStateUsing(fn ($state, Order $record) => $state.' — '.$record->voider?->name.', '.$record->voided_at?->format('M d, Y h:i A')),
                    ]),
                Section::make('Totals')
                    ->schema([
                        TextEntry::make('subtotal')->money('PHP')->inlineLabel(),
                        TextEntry::make('discount_total')->label('Discounts')->money('PHP')->inlineLabel(),
                        TextEntry::make('vat_amount')->label('VAT')->money('PHP')->inlineLabel()
                            ->visible(fn (Order $record) => (float) $record->vat_amount > 0),
                        TextEntry::make('return_credit')->label('Return credit')->money('PHP')->inlineLabel()
                            ->visible(fn (Order $record) => (float) $record->return_credit > 0),
                        TextEntry::make('total')->money('PHP')->inlineLabel()->weight('bold')->size('lg'),
                        TextEntry::make('profit')->label('Gross profit')->inlineLabel()
                            ->state(fn (Order $record) => Money::format($record->items->sum(
                                fn ($item) => $item->netAmount() * $record->exVatRatio() - ($item->isPart() ? (float) $item->unit_cost * (float) $item->qty : 0),
                            )))
                            ->visible(fn (Order $record) => $record->isPaid() && auth()->user()->can('view-cost')),
                        TextEntry::make('cashier.name')->label('Cashier')->inlineLabel()->placeholder('—'),
                        TextEntry::make('created_at')->label('Opened')->dateTime('M d, Y h:i A')->inlineLabel(),
                        TextEntry::make('paid_at')->label('Paid')->dateTime('M d, Y h:i A')->inlineLabel()->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'motorcycle', 'cashier']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('slip_no')->label('Slip')->searchable()->weight('bold')->placeholder('—')
                    ->description(fn (Order $record) => $record->order_no),
                TextColumn::make('created_at')->label('Date')->dateTime('M d, Y h:i A')->sortable()
                    ->description(fn (Order $record) => $record->paid_at ? 'paid '.$record->paid_at->format('M d, h:i A') : null),
                TextColumn::make('type')->badge(),
                TextColumn::make('customer.name')->label('Customer')->searchable()->placeholder('Walk-in')
                    ->description(fn (Order $record) => $record->motorcycle?->plate_no),
                TextColumn::make('status')->badge(),
                TextColumn::make('refund_status')->label('Refund')->badge()
                    ->formatStateUsing(fn (RefundStatus $state) => $state === RefundStatus::None ? '' : $state->getLabel())
                    ->color(fn (RefundStatus $state) => $state->getColor()),
                TextColumn::make('total')->money('PHP')->alignEnd()->sortable()
                    ->summarize(Sum::make()->money('PHP')->label('')),
                TextColumn::make('cashier.name')->label('Cashier')->toggleable(),
                TextColumn::make('motorcycle.plate_no')->label('Plate')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('order_no')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::options())->multiple(),
                SelectFilter::make('type')->options(OrderType::options()),
                SelectFilter::make('refund_status')->label('Refunds')->options(RefundStatus::options()),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_values(array_filter([
                        filled($data['from'] ?? null) ? 'From '.$data['from'] : null,
                        filled($data['until'] ?? null) ? 'Until '.$data['until'] : null,
                    ]))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            PaymentsRelationManager::class,
            ReturnsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
