<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Payments received (labels only — no payment integration). Read-only; voided
 * payments stay listed, struck through.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'method', 'receiver']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('paid_at')->label('Date')->dateTime('M d, Y h:i A')->sortable(),
                TextColumn::make('order.slip_no')->label('Slip')->searchable()->weight('bold')
                    ->url(fn (Payment $record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextColumn::make('method.name')->label('Method')->badge()->color('gray'),
                TextColumn::make('amount')->money('PHP')->alignEnd()
                    ->summarize(Sum::make()->money('PHP')->label('')
                        ->query(fn ($query) => $query->whereNull('voided_at'))),
                TextColumn::make('amount_tendered')->label('Tendered')->money('PHP')->alignEnd()->placeholder('—')->toggleable(),
                TextColumn::make('change_due')->label('Change')->money('PHP')->alignEnd()->toggleable(),
                TextColumn::make('reference_no')->label('Ref no.')->searchable()->placeholder('—')->copyable(),
                TextColumn::make('receiver.name')->label('Received by'),
                TextColumn::make('voided_at')->label('Voided')->dateTime('M d, Y')->placeholder('—')->color('danger'),
            ])
            ->recordClasses(fn (Payment $record) => $record->voided_at ? 'opacity-60 line-through' : null)
            ->filters([
                SelectFilter::make('payment_method_id')->label('Method')->relationship('method', 'name'),
                SelectFilter::make('received_by')->label('Received by')->relationship('receiver', 'name'),
                TernaryFilter::make('voided')->label('Voided')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('voided_at'),
                        false: fn (Builder $q) => $q->whereNull('voided_at'),
                    )
                    ->default(false),
                Filter::make('date')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('paid_at', '<=', $date))),
            ])
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
