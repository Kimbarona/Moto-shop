<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pos')
                ->label('Open POS')
                ->icon(Heroicon::OutlinedComputerDesktop)
                ->url('/pos'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'paid' => Tab::make('Paid')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid')),
            'pending' => Tab::make('Pending')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'voided' => Tab::make('Voided')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'voided')),
        ];
    }
}
