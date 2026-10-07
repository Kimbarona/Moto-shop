<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function stockProduct(array $attributes = []): Product
{
    $unit = Unit::firstOrCreate(['abbreviation' => 'pc'], ['name' => 'Piece']);

    return Product::create(['name' => 'Oil Filter', 'unit_id' => $unit->id, 'srp' => 180, 'avg_cost' => 0, ...$attributes]);
}

function stockTx(callable $callback): mixed
{
    return DB::transaction($callback);
}

/**
 * Recursively collect every key in a JSON payload.
 *
 * @return list<string>
 */
function allKeys(mixed $data): array
{
    if (! is_array($data)) {
        return [];
    }

    $keys = [];

    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...allKeys($value)];
    }

    return $keys;
}
