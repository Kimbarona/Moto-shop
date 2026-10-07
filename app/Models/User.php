<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\LogsChanges;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsChanges, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'must_change_password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    protected function auditExcept(): array
    {
        return ['password'];
    }

    public function mechanic(): HasOne
    {
        return $this->hasOne(Mechanic::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->canAccessBackOffice();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    /**
     * True for admins/managers AND super admins.
     */
    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isCashier(): bool
    {
        return $this->role === Role::Cashier;
    }

    public function isMechanic(): bool
    {
        return $this->role === Role::Mechanic;
    }

    public function isInventoryClerk(): bool
    {
        return $this->role === Role::InventoryClerk;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
