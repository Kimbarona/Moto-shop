<?php

namespace App\Policies;

use App\Models\User;

/**
 * Masterlist that carries prices or money settings: labor catalog, packages, mechanics,
 * payment methods, expense categories. Admins only.
 */
class AdminMasterDataPolicy extends MasterDataPolicy
{
    protected function manages(User $user): bool
    {
        return $user->isAdmin();
    }
}
