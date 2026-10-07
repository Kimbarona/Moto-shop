<?php

use App\Enums\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Spec §2 access matrix: who may open what.
*/

it('lets only admins and clerks into the back-office', function (Role $role, bool $allowed) {
    $response = $this->actingAs(User::factory()->role($role)->create())->get('/admin');

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'super admin' => [Role::SuperAdmin, true],
    'admin' => [Role::Admin, true],
    'clerk' => [Role::InventoryClerk, true],
    'cashier' => [Role::Cashier, false],
    'mechanic' => [Role::Mechanic, false],
]);

it('guards front-of-house screens by role', function (Role $role, string $path, bool $allowed) {
    $response = $this->actingAs(User::factory()->role($role)->create())->get($path);

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'cashier → POS' => [Role::Cashier, '/pos', true],
    'admin → POS' => [Role::Admin, '/pos', true],
    'mechanic → POS' => [Role::Mechanic, '/pos', false],
    'clerk → POS' => [Role::InventoryClerk, '/pos', false],
    'mechanic → service board' => [Role::Mechanic, '/service-board', true],
    'cashier → service board' => [Role::Cashier, '/service-board', true],
    'clerk → service board' => [Role::InventoryClerk, '/service-board', false],
    'clerk → stock lookup' => [Role::InventoryClerk, '/stock-lookup', true],
    'mechanic → stock lookup' => [Role::Mechanic, '/stock-lookup', true],
]);

it('never flags cost visibility for cashiers or mechanics', function (Role $role, bool $viewCost) {
    $user = User::factory()->role($role)->create();
    $path = $role === Role::Mechanic ? '/service-board' : '/stock-lookup';

    $this->actingAs($user)->get($path)->assertInertia(fn (Assert $page) => $page
        ->where('auth.can.viewCost', $viewCost)
        ->missing('auth.user.pin_hash')
        ->missing('auth.user.password'));
})->with([
    'cashier' => [Role::Cashier, false],
    'mechanic' => [Role::Mechanic, false],
    'clerk' => [Role::InventoryClerk, false],
    'admin' => [Role::Admin, true],
]);

it('maps capability gates to the matrix', function (string $ability, array $allowedRoles) {
    foreach (Role::cases() as $role) {
        $user = User::factory()->role($role)->create();

        expect($user->can($ability))->toBe(in_array($role, $allowedRoles, true), "{$role->value} → {$ability}");
    }
})->with([
    'take-payment' => ['take-payment', [Role::SuperAdmin, Role::Admin, Role::Cashier]],
    'manage-inventory' => ['manage-inventory', [Role::SuperAdmin, Role::Admin, Role::InventoryClerk]],
    'edit-pricing' => ['edit-pricing', [Role::SuperAdmin, Role::Admin]],
    'view-cost' => ['view-cost', [Role::SuperAdmin, Role::Admin]],
    'view-audit-log' => ['view-audit-log', [Role::SuperAdmin, Role::Admin]],
    'manage-settings' => ['manage-settings', [Role::SuperAdmin]],
    'view-settings' => ['view-settings', [Role::SuperAdmin, Role::Admin]],
]);

it('denies every capability to inactive users', function () {
    $user = superAdmin(['is_active' => false]);

    expect($user->can('take-payment'))->toBeFalse()
        ->and($user->can('manage-settings'))->toBeFalse();
});

it('keeps the clerk out of admin-only masterlist and users', function () {
    $this->actingAs(clerk());

    $this->get('/admin/products')->assertOk();
    $this->get('/admin/categories')->assertOk();
    $this->get('/admin/payment-methods')->assertForbidden();
    $this->get('/admin/services')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();
    $this->get('/admin/settings')->assertForbidden();
});
