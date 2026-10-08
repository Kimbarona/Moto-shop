<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->payments()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['method', 'receiver']))
            ->paginated(false)
            ->columns([
                TextColumn::make('paid_at')->label('Date')->dateTime('M d, Y h:i A'),
                TextColumn::make('method.name')->label('Method')->badge()->color('gray'),
                TextColumn::make('amount')->money('PHP')->alignEnd()->weight('bold'),
                TextColumn::make('amount_tendered')->label('Tendered')->money('PHP')->alignEnd()->placeholder('—'),
                TextColumn::make('change_due')->label('Change')->money('PHP')->alignEnd(),
                TextColumn::make('reference_no')->label('Ref no.')->placeholder('—')->copyable(),
                TextColumn::make('receiver.name')->label('Received by'),
                TextColumn::make('voided_at')->label('Voided')->dateTime('M d, Y h:i A')->placeholder('—')->color('danger')
                    ->visible(fn () => $this->getOwnerRecord()->payments()->whereNotNull('voided_at')->exists()),
            ])
            ->recordClasses(fn (Payment $record) => $record->voided_at ? 'opacity-60 line-through' : null);
    }
}
