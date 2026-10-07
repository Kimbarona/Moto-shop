<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Masterlist managed in the back-office by admins and inventory clerks
 * (categories, brands, units, motorcycles, products, customers, suppliers).
 * Price/cost fields on products are additionally gated by "edit-pricing".
 * Records are soft-deleted only; restoring is an admin action.
 */
class MasterDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->manages($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manages($user);
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->isAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    protected function manages(User $user): bool
    {
        return $user->isAdmin() || $user->isInventoryClerk();
    }
}
