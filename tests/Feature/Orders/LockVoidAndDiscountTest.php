<?php

use App\Enums\ApprovalAction;
use App\Enums\MovementType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ServiceStatus;
use App\Models\ManagerApproval;
use App\Services\Approvals\ManagerPinService;
use App\Services\Orders\VoidService;
use App\Settings\PosSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);

    $this->manager = admin();
    app(ManagerPinService::class)->setPin($this->manager, '4826');

    $settings = app(PosSettings::class);
    $settings->cashier_max_discount_percent = 5;
    $settings->allow_price_override = true;
    $settings->save();
});

describe('paid orders are locked', function () {
    it('rejects every edit after payment', function () {
        $product = $this->part(stock: 5);
        $order = $this->pendingOrder();
        $line = $this->orders()->addPart($order, $this->cashierUser, $product->id, 1);
        $this->payCash($order);

        $attempts = [
            fn () => $this->orders()->addPart($order, $this->cashierUser, $product->id, 1),
            fn () => $this->orders()->addLabor($order, $this->cashierUser, $this->labor()->id),
            fn () => $this->orders()->updateLine($order, $line, $this->cashierUser, ['qty' => 2]),
            fn () => $this->orders()->removeLine($order, $line, $this->cashierUser),
            fn () => $this->orders()->setOrderDiscount($order, admin(), 'amount', 1),
            fn () => $this->orders()->updateDetails($order, $this->cashierUser, ['notes' => 'x']),
        ];

        foreach ($attempts as $attempt) {
            expect($attempt)->toThrow(ValidationException::class, 'locked');
        }

        expect($order->items()->count())->toBe(1)
            ->and((float) $line->fresh()->qty)->toBe(1.0);
    });
});

describe('void', function () {
    it('voids a Pending order with a reason (cashier needs a PIN)', function () {
        $order = $this->pendingOrder();

        expect(fn () => app(VoidService::class)->void($order, $this->cashierUser, ''))->toThrow(ValidationException::class, 'reason');
        expect(fn () => app(VoidService::class)->void($order, $this->cashierUser, 'Changed mind'))->toThrow(ValidationException::class, 'PIN');

        $voided = app(VoidService::class)->void($order, $this->cashierUser, 'Changed mind', '4826');

        expect($voided->status)->toBe(OrderStatus::Voided)
            ->and($voided->void_reason)->toBe('Changed mind')
            ->and(ManagerApproval::where('action', ApprovalAction::VoidOrder)->where('approver_id', $this->manager->id)->exists())->toBeTrue();
    });

    it('fully reverses a same-day paid order', function () {
        $product = $this->part(stock: 5, cost: 70);
        $mechanic = $this->mechanicFor();
        $order = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id]]);
        $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);
        $labor = $this->orders()->addLabor($order, $this->cashierUser, $this->labor(fee: 500)->id);
        $paid = $this->payCash($order);
        expect((float) $labor->fresh()->commission_amount)->toBeGreaterThan(0);

        app(VoidService::class)->void($paid, $this->manager, 'Wrong customer');

        $reversal = $order->stockMovements()->where('type', MovementType::VoidReversal)->first();

        expect($order->fresh()->status)->toBe(OrderStatus::Voided)
            ->and($order->fresh()->slip_no)->toBe($paid->slip_no) // stays used
            ->and((float) $product->fresh()->qty_on_hand)->toBe(5.0)
            ->and((float) $reversal->qty)->toBe(2.0)
            ->and((float) $reversal->unit_cost)->toBe(70.0)
            ->and($order->payments()->whereNull('voided_at')->count())->toBe(0)
            ->and((float) $labor->fresh()->commission_amount)->toBe(0.0);

        expect(fn () => app(VoidService::class)->void($order->fresh(), $this->manager, 'again'))->toThrow(ValidationException::class, 'already voided');
    });

    it('blocks voiding a paid order on a later day', function () {
        $order = $this->pendingOrder();
        $this->orders()->addPart($order, $this->cashierUser, $this->part()->id, 1);
        $this->payCash($order);

        $this->travel(1)->days();

        expect(fn () => app(VoidService::class)->void($order->fresh(), $this->manager, 'Too late'))
            ->toThrow(ValidationException::class, 'Use Return / Refund');
    });

    it('never lets mechanics or clerks void', function () {
        $order = $this->pendingOrder();

        expect(fn () => app(VoidService::class)->void($order, mechanicUser(), 'x', '4826'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(VoidService::class)->void($order, clerk(), 'x', '4826'))->toThrow(AuthorizationException::class);
    });
});

describe('discount limit & manager PIN', function () {
    beforeEach(function () {
        $this->order = $this->pendingOrder();
        $this->line = $this->orders()->addPart($this->order, $this->cashierUser, $this->part(stock: 10, srp: 1000)->id, 1);
    });

    it('lets a cashier discount up to the limit without a PIN', function () {
        $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, ['discount_type' => 'percent', 'discount_value' => 5]);

        expect((float) $this->order->fresh()->total)->toBe(950.0)
            ->and(ManagerApproval::count())->toBe(0);
    });

    it('requires a manager PIN above the limit and logs the approval', function () {
        $change = ['discount_type' => 'amount', 'discount_value' => 100]; // 10%

        expect(fn () => $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, $change))
            ->toThrow(ValidationException::class, 'Manager PIN required: 10% is above the cashier limit of 5%');

        expect(fn () => $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, $change, '0000'))
            ->toThrow(ValidationException::class, 'Incorrect manager PIN');

        expect((float) $this->order->fresh()->total)->toBe(1000.0);

        $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, $change, '4826');

        $approval = ManagerApproval::firstOrFail();

        expect((float) $this->order->fresh()->total)->toBe(900.0)
            ->and($approval->action)->toBe(ApprovalAction::Discount)
            ->and($approval->approver_id)->toBe($this->manager->id)
            ->and($approval->requested_by)->toBe($this->cashierUser->id)
            ->and($approval->subject_id)->toBe($this->order->id)
            ->and((float) $approval->details['percent'])->toBe(10.0);
    });

    it('treats a price override below SRP as a discount', function () {
        expect(fn () => $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, ['unit_price' => 800]))
            ->toThrow(ValidationException::class, 'PIN');

        $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, ['unit_price' => 800], '4826');

        expect(ManagerApproval::first()->action)->toBe(ApprovalAction::PriceOverride);

        // Raising a price never needs a PIN.
        $this->orders()->updateLine($this->order, $this->line->fresh(), $this->cashierUser, ['unit_price' => 1200]);
        expect((float) $this->order->fresh()->total)->toBe(1200.0);
    });

    it('blocks cashier price overrides entirely when the setting is off', function () {
        $settings = app(PosSettings::class);
        $settings->allow_price_override = false;
        $settings->save();

        expect(fn () => $this->orders()->updateLine($this->order, $this->line, $this->cashierUser, ['unit_price' => 990], '4826'))
            ->toThrow(AuthorizationException::class, 'Price override is turned off');

        // Managers can still override.
        $this->orders()->updateLine($this->order, $this->line, $this->manager, ['unit_price' => 990]);
        expect((float) $this->order->fresh()->total)->toBe(990.0);
    });

    it('requires a PIN for an order-level discount above the limit', function () {
        expect(fn () => $this->orders()->setOrderDiscount($this->order, $this->cashierUser, 'percent', 8))
            ->toThrow(ValidationException::class, 'PIN');

        $this->orders()->setOrderDiscount($this->order, $this->cashierUser, 'percent', 8, '4826');

        expect((float) $this->order->fresh()->total)->toBe(920.0);
    });

    it('lets admins discount without a PIN and never lets mechanics discount', function () {
        $this->orders()->updateLine($this->order, $this->line, $this->manager, ['discount_type' => 'percent', 'discount_value' => 30]);
        expect((float) $this->order->fresh()->total)->toBe(700.0);

        expect(fn () => $this->orders()->updateLine($this->order, $this->line, mechanicUser(), ['discount_value' => 1]))
            ->toThrow(AuthorizationException::class);
    });

    it('needs one PIN for a discounted walk-in cart', function () {
        $product = $this->part(stock: 3, srp: 500);
        $cart = [['type' => 'part', 'product_id' => $product->id, 'qty' => 1, 'discount_type' => 'percent', 'discount_value' => 20]];
        $payment = ['payment_method_id' => $this->cash->id, 'amount_tendered' => 400];

        expect(fn () => $this->payments()->checkout($this->cashierUser, $cart, [], $payment))->toThrow(ValidationException::class, 'PIN');

        $order = $this->payments()->checkout($this->cashierUser, $cart, [], $payment, '4826');

        expect((float) $order->total)->toBe(400.0)
            ->and(ManagerApproval::count())->toBe(1);
    });
});

describe('who may edit which order', function () {
    it('lets a mechanic edit only service orders assigned to them', function () {
        $mechUser = mechanicUser();
        $mechanic = $this->mechanicFor($mechUser);
        $other = $this->mechanicFor();
        $product = $this->part(stock: 5);

        $mine = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id]]);
        $notMine = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$other->id]]);

        $line = $this->orders()->addLabor($mine, $mechUser, $this->labor()->id);
        $this->orders()->addPart($mine, $mechUser, $product->id, 1);
        $this->orders()->setServiceStatus($mine, $mechUser, ServiceStatus::Done);

        expect($line->mechanic_id)->toBe($mechanic->id)
            ->and($mine->fresh()->service_status)->toBe(ServiceStatus::Done)
            ->and(fn () => $this->orders()->addPart($notMine, $mechUser, $product->id, 1))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->payCash($mine, actor: $mechUser))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->pendingOrder($mechUser))->toThrow(AuthorizationException::class);
    });

    it('keeps the inventory clerk out of orders', function () {
        expect(fn () => $this->pendingOrder(clerk()))->toThrow(AuthorizationException::class);
    });

    it('requires the motorcycle to belong to the customer', function () {
        [$customer] = $this->customerWithBike();
        [, $otherBike] = $this->customerWithBike();

        expect(fn () => $this->pendingOrder(type: OrderType::Service, attributes: ['customer_id' => $customer->id, 'customer_motorcycle_id' => $otherBike->id]))
            ->toThrow(ValidationException::class, 'does not belong');
    });
});
