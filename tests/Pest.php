<?php

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests run against moto_shop_db_test (see phpunit.xml) — never the real database.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        expect(config('database.connections.mysql.database'))->toEndWith('_test');
        Filament::setCurrentPanel('admin');
    })
    ->in('Feature');

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes);
}

function superAdmin(array $attributes = []): User
{
    return userWithRole(Role::SuperAdmin, $attributes);
}

function admin(array $attributes = []): User
{
    return userWithRole(Role::Admin, $attributes);
}

function cashier(array $attributes = []): User
{
    return userWithRole(Role::Cashier, $attributes);
}

function mechanicUser(array $attributes = []): User
{
    return userWithRole(Role::Mechanic, $attributes);
}

function clerk(array $attributes = []): User
{
    return userWithRole(Role::InventoryClerk, $attributes);
}
