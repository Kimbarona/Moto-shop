<?php

use App\Enums\CommissionType;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ServiceStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServicePackage;
use App\Models\StockMovement;
use App\Services\Stock\AdjustmentService;
use App\Settings\PosSettings;
use App\Settings\ServiceSettings;
use App\Settings\TaxSettings;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);
});

it('deducts stock once, snapshots unit cost and assigns the first slip number', function () {
    $product = $this->part(stock: 10, srp: 150, cost: 90);
    $order = $this->pendingOrder();
    $this->orders()->addPart($order, $this->cashierUser, $product->id, 3);

    $paid = $this->payCash($order, tendered: 500);

    $sale = StockMovement::where('type', MovementType::Sale)->get();

    expect($paid->status)->toBe(OrderStatus::Paid)
        ->and($paid->slip_no)->toBe('OS-000001')
        ->and($paid->cashier_id)->toBe($this->cashierUser->id)
        ->and((float) $paid->total)->toBe(450.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(7.0)
        ->and($sale)->toHaveCount(1)
        ->and((float) $sale->first()->qty)->toBe(-3.0)
        ->and($sale->first()->reference_id)->toBe($order->id)
        ->and((float) $paid->items->first()->unit_cost)->toBe(90.0);

    $payment = $paid->payments->first();
    expect((float) $payment->amount)->toBe(450.0)
        ->and((float) $payment->amount_tendered)->toBe(500.0)
        ->and((float) $payment->change_due)->toBe(50.0);

    expect(fn () => $this->payCash($order))->toThrow(ValidationException::class, 'already Paid');
    expect(StockMovement::where('type', MovementType::Sale)->count())->toBe(1)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(7.0);
});

it('keeps slip numbers sequential with no gaps from failed payments', function () {
    $product = $this->part(stock: 10);

    $first = $this->pendingOrder();
    $this->orders()->addPart($first, $this->cashierUser, $product->id, 1);
    expect($this->payCash($first)->slip_no)->toBe('OS-000001');

    $second = $this->pendingOrder();
    $this->orders()->addPart($second, $this->cashierUser, $product->id, 1);

    // Not enough cash → fails, rolls back.
    expect(fn () => $this->payCash($second, tendered: 10))->toThrow(ValidationException::class, 'less than the amount due');
    // Missing GCash reference → fails, rolls back.
    expect(fn () => $this->payments()->pay($second, $this->cashierUser, ['payment_method_id' => $this->gcash->id]))
        ->toThrow(ValidationException::class, 'reference no');

    expect($second->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($second->fresh()->slip_no)->toBeNull()
        ->and(Payment::count())->toBe(1);

    $paid = $this->payments()->pay($second, $this->cashierUser, ['payment_method_id' => $this->gcash->id, 'reference_no' => 'GC-123456']);

    expect($paid->slip_no)->toBe('OS-000002')
        ->and($paid->payments->first()->reference_no)->toBe('GC-123456')
        ->and((float) $paid->payments->first()->change_due)->toBe(0.0);
});

it('fails the whole payment if stock ran out after it was reserved', function () {
    $product = $this->part(stock: 2);
    $order = $this->pendingOrder();
    $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);

    // Someone adjusts stock down below what this order reserved.
    $adjust = app(AdjustmentService::class);
    $draft = $adjust->createDraft(['date' => today()->toDateString(), 'reason' => 'lost', 'remarks' => 'Missing'], [['product_id' => $product->id, 'qty' => -1]], admin());
    $adjust->post($draft, admin());

    expect(fn () => $this->payCash($order))->toThrow(ValidationException::class, 'Not enough stock');

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->slip_no)->toBeNull()
        ->and((float) $product->fresh()->qty_on_hand)->toBe(1.0);
});

it('computes commission on labor lines at payment', function () {
    $mechanic = $this->mechanicFor();
    $default = $this->labor(fee: 500);                                      // 10% default
    $fixed = $this->labor(fee: 100, type: CommissionType::Fixed, value: 30);
    $none = $this->labor(fee: 200, type: CommissionType::None);
    $percent = $this->labor(fee: 400, type: CommissionType::Percent, value: 25);

    $order = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id]]);
    $a = $this->orders()->addLabor($order, $this->cashierUser, $default->id);
    $b = $this->orders()->addLabor($order, $this->cashierUser, $fixed->id, 2);
    $c = $this->orders()->addLabor($order, $this->cashierUser, $none->id);
    $d = $this->orders()->addLabor($order, $this->cashierUser, $percent->id);

    $this->payCash($order);

    expect((float) $a->fresh()->commission_amount)->toBe(50.0)   // 10% of 500
        ->and((float) $b->fresh()->commission_amount)->toBe(60.0) // 2 × 30
        ->and((float) $c->fresh()->commission_amount)->toBe(0.0)
        ->and((float) $d->fresh()->commission_amount)->toBe(100.0) // 25% of 400
        ->and($a->fresh()->mechanic_id)->toBe($mechanic->id);
});

it('bases percent commission on the net labor after discounts', function () {
    $settings = app(ServiceSettings::class);
    $settings->default_commission_percent = 10;
    $settings->save();

    $mechanic = $this->mechanicFor();
    $service = $this->labor(fee: 1000);
    $order = $this->pendingOrder(admin(), OrderType::Service, ['mechanic_ids' => [$mechanic->id]]);
    $line = $this->orders()->addLabor($order, admin(), $service->id);
    $this->orders()->updateLine($order, $line, admin(), ['discount_type' => 'amount', 'discount_value' => 200]);

    $this->payCash($order, actor: admin());

    expect((float) $line->fresh()->commission_amount)->toBe(80.0);
});

it('finalizes a service order: done, odometer updated, audit-logged', function () {
    [$customer, $bike] = $this->customerWithBike();
    $order = $this->pendingOrder(type: OrderType::Service, attributes: [
        'customer_id' => $customer->id, 'customer_motorcycle_id' => $bike->id, 'odometer_in' => 12500, 'complaint' => 'PMS',
    ]);
    $this->orders()->addLabor($order, $this->cashierUser, $this->labor()->id);
    $this->orders()->setServiceStatus($order, $this->cashierUser, ServiceStatus::Done);

    $paid = $this->payCash($order);

    expect($paid->service_status)->toBe(ServiceStatus::Done)
        ->and($bike->fresh()->last_odometer)->toBe(12500)
        ->and(Activity::where('event', 'paid')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('refuses to pay an empty order', function () {
    expect(fn () => $this->payCash($this->pendingOrder()))->toThrow(ValidationException::class, 'at least one item');
});

it('requires a customer for walk-ins when the setting says so', function () {
    $settings = app(PosSettings::class);
    $settings->allow_walk_in_without_customer = false;
    $settings->save();

    $order = $this->pendingOrder();
    $this->orders()->addPart($order, $this->cashierUser, $this->part()->id, 1);

    expect(fn () => $this->payCash($order))->toThrow(ValidationException::class, 'Pick a customer');
});

it('checks out a walk-in cart and pays it in one go', function () {
    $product = $this->part(stock: 5, srp: 250);
    $service = $this->labor(fee: 100);

    $order = $this->payments()->checkout($this->cashierUser, [
        ['type' => 'part', 'product_id' => $product->id, 'qty' => 2],
        ['type' => 'labor', 'service_id' => $service->id],
    ], [], ['payment_method_id' => $this->cash->id, 'amount_tendered' => 1000]);

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->type)->toBe(OrderType::WalkIn)
        ->and((float) $order->total)->toBe(600.0)
        ->and((float) $order->payments->first()->change_due)->toBe(400.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(3.0);
});

it('rolls back the whole checkout when payment fails', function () {
    $product = $this->part(stock: 5, srp: 250);

    expect(fn () => $this->payments()->checkout($this->cashierUser, [
        ['type' => 'part', 'product_id' => $product->id, 'qty' => 2],
    ], [], ['payment_method_id' => $this->cash->id, 'amount_tendered' => 100]))->toThrow(ValidationException::class);

    expect(Order::count())->toBe(0)
        ->and($product->reservedQty())->toBe(0.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(5.0);
});

describe('pricing & VAT', function () {
    it('computes VAT-inclusive totals', function () {
        $tax = app(TaxSettings::class);
        $tax->vat_registered = true;
        $tax->vat_rate = 12;
        $tax->prices_vat_inclusive = true;
        $tax->save();

        $order = $this->pendingOrder();
        $this->orders()->addPart($order, $this->cashierUser, $this->part(srp: 1120)->id, 1);

        expect($order->fresh())
            ->total->toEqual('1120.00')
            ->vatable->toEqual('1000.00')
            ->vat_amount->toEqual('120.00');
    });

    it('computes VAT-exclusive totals', function () {
        $tax = app(TaxSettings::class);
        $tax->vat_registered = true;
        $tax->vat_rate = 12;
        $tax->prices_vat_inclusive = false;
        $tax->save();

        $order = $this->pendingOrder();
        $this->orders()->addPart($order, $this->cashierUser, $this->part(srp: 1000)->id, 1);

        expect($order->fresh())->total->toEqual('1120.00')->vat_amount->toEqual('120.00');
    });

    it('has no VAT when not VAT-registered', function () {
        $order = $this->pendingOrder();
        $this->orders()->addPart($order, $this->cashierUser, $this->part(srp: 1000)->id, 1);

        expect($order->fresh())->total->toEqual('1000.00')->vat_amount->toEqual('0.00');
    });

    it('spreads an order discount over lines so the shares add up exactly', function () {
        $admin = admin();
        $order = $this->pendingOrder($admin);
        $a = $this->orders()->addPart($order, $admin, $this->part(srp: 100)->id, 1);
        $b = $this->orders()->addPart($order, $admin, $this->part(srp: 200)->id, 1);
        $c = $this->orders()->addLabor($order, $admin, $this->labor(fee: 33.33)->id);

        $order = $this->orders()->setOrderDiscount($order, $admin, 'amount', 100);

        $shares = [(float) $a->fresh()->discount_share, (float) $b->fresh()->discount_share, (float) $c->fresh()->discount_share];

        expect(round(array_sum($shares), 2))->toBe(100.0)
            ->and((float) $order->total)->toBe(233.33)
            ->and((float) $order->discount_total)->toBe(100.0)
            ->and($shares[1])->toBeGreaterThan($shares[0]);
    });
});

it('expands a package into lines priced to the package price and reserves its parts', function () {
    $oil = $this->part(stock: 10, srp: 380, attributes: ['unit' => 'L']);
    $filter = $this->part(stock: 3, srp: 180);
    $changeOil = $this->labor(fee: 150);
    $cvt = $this->labor(fee: 350);

    $package = ServicePackage::create(['name' => 'PMS', 'package_price' => 899, 'is_active' => true]);
    $package->items()->createMany([
        ['service_id' => $changeOil->id, 'qty' => 1],
        ['product_id' => $oil->id, 'qty' => 0.8],
        ['product_id' => $filter->id, 'qty' => 1],
        ['service_id' => $cvt->id, 'qty' => 1],
    ]);

    $order = $this->pendingOrder(type: OrderType::Service);
    $lines = $this->orders()->addPackage($order, $this->cashierUser, $package->id);

    expect($lines)->toHaveCount(4)
        ->and((float) $order->fresh()->total)->toBe(899.0)        // regular 984
        ->and((float) $order->fresh()->subtotal)->toBe(984.0)
        ->and($oil->reservedQty())->toBe(0.8)
        ->and($filter->reservedQty())->toBe(1.0)
        ->and($lines->every(fn ($line) => $line->service_package_id === $package->id))->toBeTrue();
});
