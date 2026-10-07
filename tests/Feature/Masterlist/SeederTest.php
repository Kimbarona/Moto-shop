<?php

use App\Enums\Role;
use App\Models\Mechanic;
use App\Models\MotorcycleModel;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ServicePackage;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Approvals\ManagerPinService;
use Database\Seeders\DatabaseSeeder;

it('seeds the dev data described in the spec', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::pluck('role', 'email')->map->value->all())->toMatchArray([
        'superadmin@example.com' => Role::SuperAdmin->value,
        'admin@example.com' => Role::Admin->value,
        'cashier@example.com' => Role::Cashier->value,
        'mechanic@example.com' => Role::Mechanic->value,
        'clerk@example.com' => Role::InventoryClerk->value,
    ]);

    expect(Mechanic::whereHas('user', fn ($q) => $q->where('email', 'mechanic@example.com'))->exists())->toBeTrue()
        ->and(PaymentMethod::pluck('name')->all())->toBe(['Cash', 'GCash', 'Maya', 'Bank Transfer'])
        ->and(MotorcycleModel::count())->toBe(14)
        ->and(Product::count())->toBeGreaterThanOrEqual(30)
        ->and(Product::where('is_universal', true)->count())->toBeGreaterThan(0)
        ->and(ServicePackage::where('name', 'PMS – Scooter')->first()->items)->toHaveCount(4);

    // Transactions go through the real services: stock, slips and pending jobs line up.
    $paid = Order::paid()->get();

    expect($paid)->not->toBeEmpty()
        ->and($paid->pluck('slip_no')->sort()->values()->first())->toBe('OS-000001')
        ->and(Order::pending()->where('type', 'service')->count())->toBe(3)
        ->and(Order::pending()->where('created_at', '<', now()->subDay())->count())->toBe(1)
        ->and(Product::where('qty_on_hand', '<', 0)->count())->toBe(0)
        ->and(StockMovement::orderBy('created_at')->first()->type->value)->toBe('stock_in');

    // Manager PIN 1234 works for the seeded admins.
    $approver = app(ManagerPinService::class)->verify(User::where('email', 'cashier@example.com')->first(), '1234');
    expect($approver->isAdmin())->toBeTrue();
});
