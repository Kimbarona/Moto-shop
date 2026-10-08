<?php

use App\Enums\CommissionType;
use App\Enums\OrderType;
use App\Reports\Period;
use App\Services\Approvals\ManagerPinService;
use App\Services\Orders\ReturnService;
use App\Services\Orders\VoidService;
use App\Services\Reports\SalesLedger;
use App\Settings\TaxSettings;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);
    $this->manager = admin();
    app(ManagerPinService::class)->setPin($this->manager, '4826');
    $this->ledger = app(SalesLedger::class);

    $this->returnLine = fn ($order, $line, float $qty, ?string $disposition = 'back_to_stock') => app(ReturnService::class)->process($order->fresh(), $this->manager, [
        'reason' => 'wrong_part',
        'resolution' => 'refund',
        'refund_method_id' => $this->cash->id,
        'items' => [['order_item_id' => $line->id, 'qty' => $qty, 'disposition' => $disposition]],
    ]);

    /**
     * 3 × part (SRP 100, cost 60) + labor 300, less a ₱50 order discount = ₱550 paid.
     * Then one part back to stock and one defective: each refunds 91.67.
     */
    $this->scenario = function (): array {
        $part = $this->part(stock: 10, srp: 100, cost: 60);
        $mechanic = $this->mechanicFor();
        $order = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id]]);
        $line = $this->orders()->addPart($order, $this->cashierUser, $part->id, 3);
        $this->orders()->addLabor($order, $this->cashierUser, $this->labor(fee: 300, type: CommissionType::Percent, value: 10)->id);
        $this->orders()->setOrderDiscount($order, $this->manager, 'amount', 50);
        $this->payCash($order);

        // A same-day voided sale never counts.
        $voided = $this->pendingOrder();
        $this->orders()->addPart($voided, $this->cashierUser, $part->id, 1);
        $this->payCash($voided);
        app(VoidService::class)->void($voided, $this->manager, 'Rang up twice');

        return [$order->fresh(), $line->fresh()];
    };
});

it('defines sales, refunds, net, COGS and gross profit one way', function () {
    [$order, $line] = ($this->scenario)();
    ($this->returnLine)($order, $line, 1);
    ($this->returnLine)($order, $line, 1, 'defective');

    $s = $this->ledger->summary(Period::today());

    expect($s['orders'])->toBe(1)
        ->and($s['gross'])->toBe(600.0)
        ->and($s['discounts'])->toBe(50.0)
        ->and($s['sales'])->toBe(550.0)
        ->and($s['refunds'])->toBe(183.34)
        ->and($s['net'])->toBe(366.66)
        ->and($s['vat'])->toBe(0.0)
        // 3 × 60 sold, one came back to the shelf; the defective one stays a cost.
        ->and($s['cogs'])->toBe(120.0)
        ->and($s['gross_profit'])->toBe(246.66)
        ->and($s['parts'])->toBe(275.0)
        ->and($s['labor'])->toBe(275.0)
        ->and($s['parts_refunds'])->toBe(183.34);
});

it('nets payments and refunds per method, without voided payments', function () {
    [$order, $line] = ($this->scenario)();
    ($this->returnLine)($order, $line, 1);

    $methods = collect($this->ledger->byPaymentMethod(Period::today()))->keyBy('method');

    expect($methods['Cash'])->toMatchArray(['payments' => 1, 'received' => 550.0, 'refunded' => 91.67, 'net' => 458.33]);
});

it('splits figures per day, with empty days as zeros', function () {
    [$order, $line] = ($this->scenario)();
    $this->travel(1)->days();
    ($this->returnLine)($order, $line, 1);

    $days = $this->ledger->daily(new Period(today()->subDays(2), today()));

    expect(array_keys($days))->toHaveCount(3)
        ->and($days[today()->subDays(2)->toDateString()]['sales'])->toBe(0.0)
        ->and($days[today()->subDay()->toDateString()]['sales'])->toBe(550.0)
        ->and($days[today()->subDay()->toDateString()]['refunds'])->toBe(0.0)
        ->and($days[today()->toDateString()]['refunds'])->toBe(91.67)
        ->and($days[today()->toDateString()]['net'])->toBe(-91.67);
});

it('reports categories with labor as its own line', function () {
    ($this->scenario)();

    $categories = collect($this->ledger->byCategory(Period::today()))->keyBy('category');

    expect($categories['Labor']['net'])->toBe(275.0)
        ->and($categories['Uncategorized']['net'])->toBe(275.0)
        ->and($categories['Uncategorized']['cogs'])->toBe(180.0);
});

describe('VAT', function () {
    it('takes VAT out of profit and out of refunds (VAT-inclusive prices)', function () {
        $tax = app(TaxSettings::class);
        $tax->vat_registered = true;
        $tax->vat_rate = 12;
        $tax->prices_vat_inclusive = true;
        $tax->save();

        $part = $this->part(stock: 5, srp: 112, cost: 50);
        $order = $this->pendingOrder();
        $line = $this->orders()->addPart($order, $this->cashierUser, $part->id, 2);
        $this->payCash($order);

        $s = $this->ledger->summary(Period::today());
        expect($s['sales'])->toBe(224.0)
            ->and($s['vat'])->toBe(24.0)
            ->and($s['net_ex_vat'])->toBe(200.0)
            ->and($s['gross_profit'])->toBe(100.0);

        ($this->returnLine)($order, $line->fresh(), 1);

        $s = $this->ledger->summary(Period::today());
        expect($s['refunds'])->toBe(112.0)
            ->and($s['vat'])->toBe(12.0)
            ->and($s['net_ex_vat'])->toBe(100.0)
            ->and($s['gross_profit'])->toBe(50.0);
    });

    it('refunds the VAT the customer paid when VAT is added on top of prices', function () {
        $tax = app(TaxSettings::class);
        $tax->vat_registered = true;
        $tax->vat_rate = 12;
        $tax->prices_vat_inclusive = false;
        $tax->save();

        $part = $this->part(stock: 5, srp: 100, cost: 50);
        $order = $this->pendingOrder();
        $line = $this->orders()->addPart($order, $this->cashierUser, $part->id, 2);
        $paid = $this->payCash($order);

        expect((float) $paid->total)->toBe(224.0);

        $first = ($this->returnLine)($paid, $line->fresh(), 1);
        $second = ($this->returnLine)($paid, $line->fresh(), 1);

        expect((float) $first->return_total)->toBe(112.0)
            ->and((float) $second->return_total)->toBe(112.0);

        $s = $this->ledger->summary(Period::today());
        expect($s['net'])->toBe(0.0)
            ->and($s['vat'])->toBe(0.0)
            ->and($s['cogs'])->toBe(0.0);
    });
});
