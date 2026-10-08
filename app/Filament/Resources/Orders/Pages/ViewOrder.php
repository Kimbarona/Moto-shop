<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Concerns\CallsServices;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Orders\VoidService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    use CallsServices;

    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return $order->slip_no ? "Slip {$order->slip_no}" : "Order {$order->order_no}";
    }

    protected function getHeaderActions(): array
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return [
            Action::make('resume')
                ->label('Open in POS')
                ->icon(Heroicon::OutlinedComputerDesktop)
                ->url('/pos?order='.$order->id)
                ->visible($order->isPending()),
            Action::make('return')
                ->label('Return / refund')
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('gray')
                ->url('/returns/new?order='.$order->id)
                ->visible(fn () => auth()->user()->can('return', $order)),
            Action::make('void')
                ->label('Void')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->visible(fn () => auth()->user()->can('void', $order) && ($order->isPending()
                    || ($order->isPaid() && $order->paid_at?->isToday() && $order->refund_status->value === 'none')))
                ->requiresConfirmation()
                ->modalDescription($order->isPaid()
                    ? 'Stock comes back, the payment is voided and commission is reversed. The slip number stays used.'
                    : 'The reserved parts are released.')
                ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)])
                ->action(function (array $data) use ($order) {
                    $this->callService(fn () => app(VoidService::class)->void($order, auth()->user(), $data['reason']), 'mountedActions.0.data');
                    Notification::make()->success()->title("{$order->order_no} voided")->send();
                    $this->refreshFormData(['status']);
                    $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
                }),
            ActionGroup::make([
                Action::make('slip')
                    ->label('Reprint order slip')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url('/print/slip/'.$order->id.'?reprint=1', shouldOpenInNewTab: true)
                    ->visible(! $order->isPending()),
                Action::make('jobTicket')
                    ->label('Reprint job ticket')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url('/print/job-ticket/'.$order->id.'?reprint=1', shouldOpenInNewTab: true)
                    ->visible($order->isService()),
                Action::make('timeline')
                    ->label('Timeline (front office)')
                    ->icon(Heroicon::OutlinedClock)
                    ->url('/orders/'.$order->id, shouldOpenInNewTab: true),
            ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray'),
        ];
    }
}
