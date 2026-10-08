<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Models\SalesReturn;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->returns()->exists();
    }

    public function table(Table $table): Table
    {
        return SalesReturnResource::table($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['refundMethod', 'processor', 'exchangeOrder']))
            ->paginated(false)
            ->filters([])
            ->recordUrl(fn (SalesReturn $record) => SalesReturnResource::getUrl('view', ['record' => $record]));
    }
}
