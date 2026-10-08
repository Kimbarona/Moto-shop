<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

/**
 * Expenses: admins record and correct them; mistakes are voided with a reason, never
 * deleted. A voided expense is locked.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->isAdmin();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->viewAny($user) && ! $expense->isVoided();
    }

    public function void(User $user, Expense $expense): bool
    {
        return $this->update($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return false;
    }
}
