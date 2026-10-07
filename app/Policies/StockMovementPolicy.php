<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

/**
 * The stock ledger is append-only and read-only in the UI.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-inventory');
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $user->can('manage-inventory');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
