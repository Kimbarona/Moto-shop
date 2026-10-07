<?php

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\Users\UserManagementService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->users = app(UserManagementService::class);
});

it('makes users created by someone else change their password on first login', function () {
    $user = $this->users->create(admin(), [
        'name' => 'New Cashier', 'email' => 'NEW@Example.com', 'password' => 'temp-pass-1', 'role' => Role::Cashier,
    ]);

    expect($user->must_change_password)->toBeTrue()
        ->and($user->email)->toBe('new@example.com');
});

it('does not let an admin create or manage super admins or admins', function () {
    $admin = admin();

    expect(fn () => $this->users->create($admin, [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'temp-pass-1', 'role' => Role::SuperAdmin,
    ]))->toThrow(ValidationException::class);

    expect(fn () => $this->users->create($admin, [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'temp-pass-1', 'role' => Role::Admin,
    ]))->toThrow(ValidationException::class);

    $super = superAdmin();
    expect(fn () => $this->users->update($admin, $super, ['is_active' => false]))->toThrow(ValidationException::class)
        ->and($admin->can('update', $super))->toBeFalse()
        ->and($admin->can('update', admin()))->toBeFalse()
        ->and($admin->can('update', cashier()))->toBeTrue();
});

it('lets an admin manage cashiers, mechanics and clerks', function () {
    $cashier = cashier();

    $this->users->update(admin(), $cashier, ['role' => Role::InventoryClerk, 'is_active' => false]);

    expect($cashier->fresh())->role->toBe(Role::InventoryClerk)->is_active->toBeFalse();
});

it('does not let users change their own role or deactivate themselves', function () {
    $super = superAdmin();
    superAdmin(); // a second one, so the last-super-admin rule is not what blocks this

    expect(fn () => $this->users->update($super, $super, ['role' => Role::Admin]))
        ->toThrow(ValidationException::class, 'own role');

    expect(fn () => $this->users->update($super, $super, ['is_active' => false]))
        ->toThrow(ValidationException::class, 'deactivate your own');
});

it('always keeps at least one active super admin', function () {
    $first = superAdmin();
    $second = superAdmin();

    // With two active super admins, one may demote the other…
    $this->users->update($first, $second, ['role' => Role::Admin]);

    // …but the last active one cannot be demoted or deactivated, even by another
    // (inactive) super admin account.
    $dormant = superAdmin(['is_active' => false]);

    expect(fn () => $this->users->update($dormant, $first, ['role' => Role::Admin]))
        ->toThrow(ValidationException::class, 'last active Super Admin');

    expect(fn () => $this->users->update($dormant, $first, ['is_active' => false]))
        ->toThrow(ValidationException::class, 'last active Super Admin');

    expect(User::active()->where('role', Role::SuperAdmin)->count())->toBe(1);
});

it('clears the manager PIN when a user is moved out of a manager role', function () {
    $manager = admin();
    $manager->forceFill(['pin_hash' => bcrypt('4321')])->save();

    $this->users->update(superAdmin(), $manager, ['role' => Role::Cashier]);

    expect($manager->fresh()->pin_hash)->toBeNull();
});

it('resets a password and forces a change on next login', function () {
    $cashier = cashier();

    $this->users->resetPassword(admin(), $cashier, 'temp-pass-2');

    expect($cashier->fresh()->must_change_password)->toBeTrue();
});

it('never deletes users', function () {
    expect(superAdmin()->can('delete', cashier()))->toBeFalse();
});

it('enforces the rules through the Filament user form', function () {
    $admin = admin();
    $this->actingAs($admin);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Bad', 'email' => 'bad@example.com', 'role' => 'super_admin', 'password' => 'temp-pass-1', 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['role']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Mekaniko', 'email' => 'meka@example.com', 'role' => 'mechanic', 'password' => 'temp-pass-1', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'meka@example.com')->first())
        ->role->toBe(Role::Mechanic)
        ->must_change_password->toBeTrue();

    $super = superAdmin();
    $this->actingAs($super);

    Livewire::test(EditUser::class, ['record' => $super->getRouteKey()])
        ->fillForm(['is_active' => false])
        ->call('save');

    expect($super->fresh()->is_active)->toBeTrue();
});
