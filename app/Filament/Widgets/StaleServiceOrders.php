<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Pending service orders older than 24 hours (bikes sitting in the shop).
 */
class StaleServiceOrders extends TableWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 3];

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Service orders open over 24h')
            ->query(fn () => Order::query()->pending()->where('type', 'service')
                ->where('created_at', '<', now()->subDay())
                ->with(['customer', 'motorcycle', 'mechanics'])
                ->oldest())
            ->paginated([5])
            ->columns([
                TextColumn::make('order_no')->label('Order')->weight('bold')
                    ->description(fn (Order $record) => $record->customer?->name),
                TextColumn::make('motorcycle.plate_no')->label('Plate')->placeholder('—'),
                TextColumn::make('service_status')->label('Status')->badge(),
                TextColumn::make('mechanics.name')->label('Mechanic')->placeholder('—'),
                TextColumn::make('created_at')->label('In shop')->since()->color('danger'),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No bikes waiting over a day')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
