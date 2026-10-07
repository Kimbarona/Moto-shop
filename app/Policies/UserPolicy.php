<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Users\UserManagementService;

/**
 * Super admins manage everyone; admins manage cashiers, mechanics and clerks.
 * Users are deactivated, never deleted.
 */
class UserPolicy
{
    public function __construct(private readonly UserManagementService $users) {}

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin() || $user->is($model);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $this->users->canManage($user, $model);
    }

    public function changeRole(User $user, User $model): bool
    {
        return $this->users->canManage($user, $model) && ! $user->is($model);
    }

    public function deactivate(User $user, User $model): bool
    {
        return $this->users->canManage($user, $model) && ! $user->is($model);
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $this->users->canManage($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
