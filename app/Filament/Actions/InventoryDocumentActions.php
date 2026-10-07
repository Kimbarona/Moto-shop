<?php

namespace App\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Post / Cancel actions shared by Stock In, Adjustments and Physical Counts.
 * Business rules live in the services; their ValidationExceptions become notifications.
 */
class InventoryDocumentActions
{
    /**
     * @param  Closure(Model): mixed  $post
     * @param  (Closure(Model): string|null)|null  $description  confirmation text (e.g. warnings)
     */
    public static function post(Closure $post, ?Closure $description = null): Action
    {
        return Action::make('post')
            ->label('Post')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Model $record) => auth()->user()->can('post', $record))
            ->requiresConfirmation()
            ->modalHeading('Post this document?')
            ->modalDescription(fn (Model $record) => $description?->__invoke($record)
                ?? 'Stock will be updated now. Posted documents can no longer be edited.')
            ->modalSubmitActionLabel('Post')
            ->action(function (Model $record, Action $action) use ($post) {
                static::run(fn () => $post($record), $action, 'Posted', 'Stock has been updated.');
            });
    }

    /**
     * @param  Closure(Model): mixed  $cancel
     */
    public static function cancel(Closure $cancel): Action
    {
        return Action::make('cancelDocument')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Model $record) => auth()->user()->can('cancel', $record))
            ->requiresConfirmation()
            ->modalHeading('Cancel this draft?')
            ->modalDescription('The draft is kept for the record but can no longer be posted.')
            ->action(function (Model $record, Action $action) use ($cancel) {
                static::run(fn () => $cancel($record), $action, 'Cancelled');
            });
    }

    public static function run(Closure $callback, Action $action, string $successTitle, ?string $successBody = null): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Could not complete')
                ->body(collect($e->errors())->flatten()->first())
                ->persistent()
                ->send();

            $action->halt();
        }

        Notification::make()->success()->title($successTitle)->body($successBody)->send();
    }
}
