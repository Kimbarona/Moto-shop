<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Business rules live in app/Services and throw plain ValidationExceptions keyed by
 * field name (qty, role…). Filament forms keep state under "data.", so map the keys
 * and also surface the message as a notification.
 */
trait CallsServices
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function callService(callable $callback, string $statePath = 'data'): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Not saved')->body(collect($e->errors())->flatten()->first())->send();

            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["{$statePath}.{$key}" => $messages])->all(),
            );
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('Not allowed')->body($e->getMessage())->send();

            throw new Halt;
        }
    }
}
