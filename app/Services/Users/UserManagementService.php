<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * User safety rules:
 *  - super admins manage everyone; admins manage cashiers, mechanics and clerks only
 *  - nobody changes their own role or deactivates themselves
 *  - there is always at least one active super admin
 *  - accounts created or reset by someone else must change their password on first login
 */
class UserManagementService
{
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->role === Role::Admin && ! $target->isAdmin();
    }

    /**
     * @return list<Role>
     */
    public function assignableRoles(User $actor): array
    {
        return match (true) {
            $actor->isSuperAdmin() => Role::cases(),
            $actor->role === Role::Admin => [Role::Cashier, Role::Mechanic, Role::InventoryClerk],
            default => [],
        };
    }

    /**
     * @param  array{name: string, email: string, password: string, role: Role|string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function create(User $actor, array $data): User
    {
        $role = $this->role($data['role']);
        $this->ensureCanAssign($actor, $role);

        return User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => $data['password'],
            'role' => $role,
            'is_active' => $data['is_active'] ?? true,
            'must_change_password' => true,
        ]);
    }

    /**
     * @param  array{name?: string, email?: string, role?: Role|string, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function update(User $actor, User $target, array $data): User
    {
        $this->ensureCanManage($actor, $target);

        return DB::transaction(function () use ($actor, $target, $data) {
            $newRole = isset($data['role']) ? $this->role($data['role']) : $target->role;
            $newActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $target->is_active;

            if ($actor->is($target)) {
                if ($newRole !== $target->role) {
                    throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
                }

                if (! $newActive) {
                    throw ValidationException::withMessages(['is_active' => 'You cannot deactivate your own account.']);
                }
            }

            if ($newRole !== $target->role) {
                $this->ensureCanAssign($actor, $newRole);
            }

            $losesSuperAdmin = $target->isSuperAdmin() && $target->is_active
                && ($newRole !== Role::SuperAdmin || ! $newActive);

            if ($losesSuperAdmin && $this->activeSuperAdminCount() <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'This is the last active Super Admin. Create or activate another Super Admin first.',
                ]);
            }

            $target->fill(array_filter([
                'name' => $data['name'] ?? null,
                'email' => isset($data['email']) ? Str::lower($data['email']) : null,
            ]));
            $target->role = $newRole;
            $target->is_active = $newActive;

            if (! $newRole->isAdmin()) {
                $target->pin_hash = null; // PINs are for managers only
            }

            $target->save();

            return $target;
        });
    }

    /**
     * @throws ValidationException
     */
    public function resetPassword(User $actor, User $target, string $password): void
    {
        $this->ensureCanManage($actor, $target);

        $target->forceFill([
            'password' => $password,
            'must_change_password' => ! $actor->is($target),
        ])->save();

        activity('User')->causedBy($actor)->performedOn($target)->event('password_reset')->log('Password reset');
    }

    public function activeSuperAdminCount(): int
    {
        // Lock the rows so two concurrent demotions cannot both pass the check.
        return User::query()->active()->where('role', Role::SuperAdmin)->lockForUpdate()->count();
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanManage(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            throw ValidationException::withMessages(['role' => 'You are not allowed to manage this user.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanAssign(User $actor, Role $role): void
    {
        if (! in_array($role, $this->assignableRoles($actor), true)) {
            throw ValidationException::withMessages(['role' => 'You are not allowed to assign the '.$role->getLabel().' role.']);
        }
    }

    private function role(Role|string $role): Role
    {
        return $role instanceof Role ? $role : Role::from($role);
    }
}
