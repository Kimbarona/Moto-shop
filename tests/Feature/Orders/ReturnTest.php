<?php

use App\Enums\ApprovalAction;
use App\Enums\CommissionType;
use App\Enums\DefectiveStatus;
use App\Enums\Disposition;
use App\Enums\MovementType;
use App\Enums\OrderType;
use App\Enums\RefundStatus;
use App\Models\DefectiveStock;
use App\Models\ManagerApproval;
use App\Models\Order;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Services\Approvals\ManagerPinService;
use App\Services\Orders\ReturnService;
use App\Services\Orders\VoidService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);

    $this->manager = admin();
    app(ManagerPinService::class)->setPin($this->manager, '4826');

    $this->returns = app(ReturnService::class);

    // A paid walk-in order with one part line: [order, line, product].
    $this->paidParts = function (float $qty = 3, float $srp = 100, float $cost = 60, array $orderDiscount = []): array {
        $product = $this->part(stock: 10, srp: $srp, cost: $cost);
        $order = $this->pendingOrder();
        $line = $this->orders()->addPart($order, $this->cashierUser, $product->id, $qty);

        if ($orderDiscount !== []) {
            $this->orders()->setOrderDiscount($order, $this->manager, ...$orderDiscount);
        }

        $this->payCash($order);

        return [$order->fresh(), $line->fresh(), $product->fresh()];
    };

    // Return a list of lines from a paid order as a refund in cash (admin: no PIN).
    $this->refund = function (Order $order, array $lines, array $data = [], $actor = null, ?string $pin = null): SalesReturn {
        return $this->returns->process($order, $actor ?? $this->manager, [
            'reason' => 'wrong_part',
            'resolution' => 'refund',
            'refund_method_id' => $this->cash->id,
            'items' => $lines,
            ...$data,
        ], $pin);
    };
});

describe('parts', function () {
    it('puts back_to_stock parts on the shelf at the original unit cost', function () {
        [$order, $line, $product] = ($this->paidParts)(qty: 3, srp: 100, cost: 60);

        expect((float) $product->qty_on_hand)->toBe(7.0);

        $return = ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 2, 'disposition' => 'back_to_stock']]);

        $movement = StockMovement::where('type', MovementType::ReturnToStock)->sole();

        expect($return->return_no)->toBe('RET-000001')
            ->and((float) $return->return_total)->toBe(200.0)
            ->and((float) $return->refund_amount)->toBe(200.0)
            ->and($return->refund_method_id)->toBe($this->cash->id)
            ->and((float) $product->fresh()->qty_on_hand)->toBe(9.0)
            ->and((float) $movement->qty)->toBe(2.0)
            ->and((float) $movement->unit_cost)->toBe(60.0)
            ->and((float) $movement->balance_after)->toBe(9.0)
            ->and($movement->reference_id)->toBe($return->id)
            ->and((float) $line->fresh()->qty_returned)->toBe(2.0)
            ->and($order->fresh()->refund_status)->toBe(RefundStatus::Partial)
            ->and((float) $return->items->first()->unit_cost)->toBe(60.0);
    });

    it('sends defective parts to the defective bucket, not to sellable stock', function () {
        [$order, $line, $product] = ($this->paidParts)(qty: 2, cost: 75);

        $return = ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'defective']], ['reason' => 'defective']);

        $defective = DefectiveStock::sole();

        expect((float) $product->fresh()->qty_on_hand)->toBe(8.0)
            ->and(StockMovement::where('type', MovementType::ReturnToStock)->exists())->toBeFalse()
            ->and($defective->product_id)->toBe($product->id)
            ->and((float) $defective->qty)->toBe(1.0)
            ->and((float) $defective->unit_cost)->toBe(75.0)
            ->and($defective->status)->toBe(DefectiveStatus::Pending)
            ->and($defective->return_item_id)->toBe($return->items->first()->id)
            ->and($return->items->first()->disposition)->toBe(Disposition::Defective)
            ->and($product->fresh()->availableQty())->toBe(8.0);
    });

    it('needs a disposition for parts', function () {
        [$order, $line] = ($this->paidParts)();

        expect(fn () => ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 1]]))
            ->toThrow(ValidationException::class, 'back to stock or defective');
    });
});

describe('quantities and refund status', function () {
    it('never returns more than was sold, across several returns', function () {
        [$order, $line] = ($this->paidParts)(qty: 3);

        expect(fn () => ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 4, 'disposition' => 'back_to_stock']]))
            ->toThrow(ValidationException::class, 'Only 3');

        ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 2, 'disposition' => 'back_to_stock']]);

        expect(fn () => ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 2, 'disposition' => 'back_to_stock']]))
            ->toThrow(ValidationException::class, 'Only 1');

        ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']]);

        expect($order->fresh()->refund_status)->toBe(RefundStatus::Full)
            ->and(SalesReturn::count())->toBe(2);

        expect(fn () => ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']]))
            ->toThrow(ValidationException::class, 'already returned');
    });

    it('rejects lines from another order, duplicate lines and empty returns', function () {
        [$order, $line] = ($this->paidParts)();
        [, $otherLine] = ($this->paidParts)();

        expect(fn () => ($this->refund)($order, [['order_item_id' => $otherLine->id, 'qty' => 1, 'disposition' => 'back_to_stock']]))
            ->toThrow(ValidationException::class, 'not on this order');
        expect(fn () => ($this->refund)($order, [
            ['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock'],
            ['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock'],
        ]))->toThrow(ValidationException::class, 'only be listed once');
        expect(fn () => ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 0, 'disposition' => 'back_to_stock']]))
            ->toThrow(ValidationException::class, 'at least one item');

        expect(SalesReturn::count())->toBe(0);
    });

    it('refunds what the customer actually paid, and the last return takes the remainder', function () {
        // 3 × ₱100 less a ₱200 order discount = ₱100 paid → 33.33 + 33.33 + 33.34.
        [$order, $line] = ($this->paidParts)(qty: 3, srp: 100, orderDiscount: ['amount', 200]);
        expect($line->netAmount())->toBe(100.0);

        $amounts = collect([1, 1, 1])->map(fn ($qty) => (float) ($this->refund)($order, [
            ['order_item_id' => $line->id, 'qty' => $qty, 'disposition' => 'back_to_stock'],
        ])->return_total);

        expect($amounts->all())->toBe([33.33, 33.33, 33.34])
            ->and(round($amounts->sum(), 2))->toBe(100.0);
    });

    it('only returns paid orders, and blocks a same-day void once returned', function () {
        $pending = $this->pendingOrder();
        $this->orders()->addPart($pending, $this->cashierUser, $this->part()->id, 1);

        expect(fn () => ($this->refund)($pending, []))->toThrow(ValidationException::class, 'Only paid orders');

        [$order, $line] = ($this->paidParts)();
        ($this->refund)($order, [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']]);

        expect(fn () => app(VoidService::class)->void($order, $this->manager, 'oops'))
            ->toThrow(ValidationException::class, 'already has a return');

        [$voided] = ($this->paidParts)();
        app(VoidService::class)->void($voided, $this->manager, 'mistake');

        expect(fn () => ($this->refund)($voided, []))->toThrow(ValidationException::class, 'Only paid orders');
    });
});

describe('labor', function () {
    it('refunds labor as an amount only and reverses the commission pro rata', function () {
        $mechanic = $this->mechanicFor();
        $order = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id]]);
        $labor = $this->orders()->addLabor($order, $this->cashierUser, $this->labor(fee: 100, type: CommissionType::Percent, value: 10)->id, 3);
        $this->payCash($order);

        $labor->refresh();
        expect((float) $labor->commission_amount)->toBe(30.0);

        $movementsBefore = StockMovement::count();
        $first = ($this->refund)($order->fresh(), [['order_item_id' => $labor->id, 'qty' => 1]]);
        $second = ($this->refund)($order->fresh(), [['order_item_id' => $labor->id, 'qty' => 2]]);

        expect((float) $first->return_total)->toBe(100.0)
            ->and((float) $first->items->first()->commission_reversed)->toBe(10.0)
            ->and($first->items->first()->disposition)->toBeNull()
            ->and((float) $second->items->first()->commission_reversed)->toBe(20.0)
            ->and(StockMovement::count())->toBe($movementsBefore)
            ->and($order->fresh()->refund_status)->toBe(RefundStatus::Full)
            // The earned commission stays on the line; reversals are dated with the return.
            ->and((float) $labor->fresh()->commission_amount)->toBe(30.0);
    });
});

describe('refund method', function () {
    it('needs a method, and a reference for e-wallets', function () {
        [$order, $line] = ($this->paidParts)();
        $lines = [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']];

        expect(fn () => ($this->refund)($order, $lines, ['refund_method_id' => null]))
            ->toThrow(ValidationException::class, 'how the money is given back');
        expect(fn () => ($this->refund)($order, $lines, ['refund_method_id' => $this->gcash->id]))
            ->toThrow(ValidationException::class, 'reference no');

        $return = ($this->refund)($order, $lines, ['refund_method_id' => $this->gcash->id, 'refund_reference' => 'GC-998877']);

        expect($return->refund_reference)->toBe('GC-998877');
    });
});

describe('approval', function () {
    it('needs a manager PIN for cashiers and records the approver', function () {
        [$order, $line] = ($this->paidParts)();
        $lines = [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']];

        expect(fn () => ($this->refund)($order, $lines, actor: $this->cashierUser))
            ->toThrow(ValidationException::class, 'Manager PIN required');
        expect(fn () => ($this->refund)($order, $lines, actor: $this->cashierUser, pin: '0000'))
            ->toThrow(ValidationException::class, 'Incorrect manager PIN');
        expect(SalesReturn::count())->toBe(0);

        $return = ($this->refund)($order, $lines, actor: $this->cashierUser, pin: '4826');

        expect($return->approved_by)->toBe($this->manager->id)
            ->and($return->processed_by)->toBe($this->cashierUser->id)
            ->and(ManagerApproval::where('action', ApprovalAction::Return)->where('approver_id', $this->manager->id)->exists())->toBeTrue();
    });

    it('never lets mechanics or clerks process returns', function () {
        [$order, $line] = ($this->paidParts)();
        $lines = [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'back_to_stock']];

        expect(fn () => ($this->refund)($order, $lines, actor: mechanicUser()))->toThrow(AuthorizationException::class);
        expect(fn () => ($this->refund)($order, $lines, actor: clerk()))->toThrow(AuthorizationException::class);
    });
});

describe('exchange', function () {
    beforeEach(function () {
        [$this->order, $this->line, $this->product] = ($this->paidParts)(qty: 2, srp: 500);

        $this->exchange = $this->returns->process($this->order, $this->manager, [
            'reason' => 'wrong_part',
            'resolution' => 'exchange',
            'items' => [['order_item_id' => $this->line->id, 'qty' => 1, 'disposition' => 'back_to_stock']],
        ]);

        $this->checkoutWith = fn (float $srp, ?float $tendered = null) => $this->payments()->checkout(
            $this->cashierUser,
            [['type' => 'part', 'product_id' => $this->part(stock: 5, srp: $srp)->id, 'qty' => 1]],
            ['exchange_return_id' => $this->exchange->id, 'customer_id' => $this->order->customer_id],
            ['payment_method_id' => $this->cash->id, 'amount_tendered' => $tendered],
        );
    });

    it('records the return as credit, with no money paid out yet', function () {
        expect((float) $this->exchange->return_total)->toBe(500.0)
            ->and((float) $this->exchange->refund_amount)->toBe(0.0)
            ->and($this->exchange->refund_method_id)->toBeNull()
            ->and(SalesReturn::unusedExchange()->pluck('id')->all())->toBe([$this->exchange->id]);
    });

    it('applies the credit to a new sale, and the customer pays the difference', function () {
        $sale = ($this->checkoutWith)(800, tendered: 300);

        expect((float) $sale->total)->toBe(800.0)
            ->and((float) $sale->return_credit)->toBe(500.0)
            ->and($sale->amountDue())->toBe(300.0)
            ->and((float) $sale->payments->sole()->amount)->toBe(300.0)
            ->and($sale->exchange_return_id)->toBe($this->exchange->id)
            ->and((float) $this->exchange->fresh()->refund_amount)->toBe(0.0)
            ->and(SalesReturn::unusedExchange()->count())->toBe(0);
    });

    it('gives back the difference when the new sale costs less than the credit', function () {
        $sale = ($this->checkoutWith)(350);

        expect((float) $sale->return_credit)->toBe(350.0)
            ->and($sale->amountDue())->toBe(0.0)
            ->and($sale->payments)->toHaveCount(0)
            ->and((float) $this->exchange->fresh()->refund_amount)->toBe(150.0)
            ->and($this->exchange->fresh()->refund_method_id)->toBe($this->cash->id);
    });

    it('uses the credit only once, and frees it again when the sale is voided', function () {
        $sale = ($this->checkoutWith)(350);

        expect(fn () => ($this->checkoutWith)(100))->toThrow(ValidationException::class, 'already used');

        app(VoidService::class)->void($sale, $this->manager, 'Wrong item rung up');

        expect((float) $this->exchange->fresh()->refund_amount)->toBe(0.0)
            ->and(SalesReturn::unusedExchange()->count())->toBe(1);

        expect((float) ($this->checkoutWith)(600, tendered: 100)->return_credit)->toBe(500.0);
    });

    it('ignores any credit amount sent by the client and refuses refund returns', function () {
        $refund = ($this->refund)($this->order, [['order_item_id' => $this->line->id, 'qty' => 1, 'disposition' => 'back_to_stock']]);

        expect(fn () => $this->payments()->checkout(
            $this->cashierUser,
            [['type' => 'part', 'product_id' => $this->product->id, 'qty' => 1]],
            ['exchange_return_id' => $refund->id],
            ['payment_method_id' => $this->cash->id],
        ))->toThrow(ValidationException::class, 'not an exchange');

        $sale = $this->payments()->checkout(
            $this->cashierUser,
            [['type' => 'part', 'product_id' => $this->product->id, 'qty' => 1]],
            ['exchange_return_id' => $this->exchange->id, 'return_credit' => 99999],
            ['payment_method_id' => $this->cash->id],
        );

        expect((float) $sale->return_credit)->toBe(500.0);
    });
});
