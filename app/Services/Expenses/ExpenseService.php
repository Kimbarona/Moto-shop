<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Expenses are never deleted: a wrong entry is voided with a reason (and left out of
 * reports), then entered again correctly.
 */
class ExpenseService
{
    public function void(Expense $expense, User $actor, string $reason): Expense
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => 'Give a reason for voiding.']);
        }

        return DB::transaction(function () use ($expense, $actor, $reason) {
            /** @var Expense $expense */
            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->getKey());

            if ($expense->isVoided()) {
                throw ValidationException::withMessages(['void_reason' => 'This expense was already voided.']);
            }

            Gate::forUser($actor)->authorize('void', $expense);

            $expense->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            return $expense;
        });
    }
}
