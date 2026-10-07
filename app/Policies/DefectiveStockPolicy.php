<?php

namespace App\Policies;

use App\Models\DefectiveStock;
use App\Models\User;

class DefectiveStockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-inventory');
    }

    public function view(User $user, DefectiveStock $defective): bool
    {
        return $user->can('manage-inventory');
    }

    public function create(User $user): bool
    {
        return $user->can('manage-inventory');
    }

    public function update(User $user, DefectiveStock $defective): bool
    {
        return false;
    }

    public function resolve(User $user, DefectiveStock $defective): bool
    {
        return $user->can('manage-inventory') && $defective->isPending();
    }

    public function delete(User $user, DefectiveStock $defective): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
