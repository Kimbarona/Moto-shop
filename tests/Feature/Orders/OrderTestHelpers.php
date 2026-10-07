<?php

namespace Tests\Feature\Orders;

use App\Enums\CommissionType;
use App\Enums\OrderType;
use App\Enums\PaymentMethodType;
use App\Models\Customer;
use App\Models\CustomerMotorcycle;
use App\Models\Mechanic;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Models\Unit;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\PaymentService;
use App\Services\Stock\ReceivingService;

/**
 * Shared fixtures for order tests (used via `uses()` in each file).
 */
trait OrderTestHelpers
{
    protected User $cashierUser;

    protected PaymentMethod $cash;

    protected PaymentMethod $gcash;

    protected function setUpOrderFixtures(): void
    {
        $this->cashierUser = cashier();
        $this->cash = PaymentMethod::create(['name' => 'Cash', 'type' => PaymentMethodType::Cash, 'is_active' => true]);
        $this->gcash = PaymentMethod::create(['name' => 'GCash', 'type' => PaymentMethodType::Ewallet, 'requires_reference' => true, 'is_active' => true]);
    }

    protected function part(float $stock = 10, float $srp = 100, float $cost = 60, array $attributes = []): Product
    {
        $unit = Unit::firstOrCreate(['abbreviation' => $attributes['unit'] ?? 'pc'], [
            'name' => ($attributes['unit'] ?? 'pc') === 'L' ? 'Liter' : 'Piece',
            'allows_decimal' => ($attributes['unit'] ?? 'pc') === 'L',
        ]);
        unset($attributes['unit']);

        $product = Product::create(['name' => 'Part '.uniqid(), 'unit_id' => $unit->id, 'srp' => $srp, 'avg_cost' => $cost, ...$attributes]);

        if ($stock > 0) {
            $receiving = app(ReceivingService::class)->createDraft(['date' => today()->toDateString()], [
                ['product_id' => $product->id, 'qty' => $stock, 'unit_cost' => $cost],
            ], $this->cashierUser);
            app(ReceivingService::class)->post($receiving, $this->cashierUser);
        }

        return $product->refresh();
    }

    protected function labor(float $fee = 300, ?CommissionType $type = null, ?float $value = null): Service
    {
        return Service::create([
            'code' => 'S'.uniqid(), 'name' => 'Labor '.uniqid(), 'labor_fee' => $fee,
            'commission_type' => $type, 'commission_value' => $value, 'is_active' => true,
        ]);
    }

    protected function mechanicFor(?User $user = null): Mechanic
    {
        return Mechanic::create(['name' => 'Mechanic '.uniqid(), 'user_id' => $user?->id, 'is_active' => true]);
    }

    protected function customerWithBike(): array
    {
        $customer = Customer::create(['name' => 'Juan '.uniqid()]);
        $bike = CustomerMotorcycle::create(['customer_id' => $customer->id, 'plate_no' => 'ABC '.random_int(100, 999).uniqid(), 'last_odometer' => 1000]);

        return [$customer, $bike];
    }

    protected function pendingOrder(?User $actor = null, OrderType $type = OrderType::WalkIn, array $attributes = []): Order
    {
        if ($type === OrderType::Service && ! isset($attributes['customer_id'])) {
            [$customer, $bike] = $this->customerWithBike();
            $attributes += ['customer_id' => $customer->id, 'customer_motorcycle_id' => $bike->id];
        }

        return app(OrderService::class)->create($actor ?? $this->cashierUser, $type, $attributes);
    }

    protected function orders(): OrderService
    {
        return app(OrderService::class);
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function payCash(Order $order, ?float $tendered = null, ?User $actor = null): Order
    {
        return $this->payments()->pay($order, $actor ?? $this->cashierUser, [
            'payment_method_id' => $this->cash->id,
            'amount_tendered' => $tendered ?? (float) $order->fresh()->total,
        ]);
    }
}
