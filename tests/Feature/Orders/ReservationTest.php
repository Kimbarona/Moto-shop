<?php

use App\Enums\OrderType;
use App\Models\Product;
use App\Services\Orders\VoidService;
use App\Settings\InventorySettings;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);
});

it('reserves parts on Pending orders and shows on hand / reserved / available', function () {
    $product = $this->part(stock: 5);
    $order = $this->pendingOrder();

    $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);

    $row = Product::withStockLevels()->find($product->id);

    expect((float) $row->qty_on_hand)->toBe(5.0)
        ->and((float) $row->reserved_qty)->toBe(2.0)
        ->and((float) $row->available_qty)->toBe(3.0);
});

it('blocks adding more than is available and says how many are left', function () {
    $product = $this->part(stock: 3);
    $first = $this->pendingOrder();
    $this->orders()->addPart($first, $this->cashierUser, $product->id, 2);

    $second = $this->pendingOrder();

    expect(fn () => $this->orders()->addPart($second, $this->cashierUser, $product->id, 2))
        ->toThrow(ValidationException::class, 'Only 1 pc of '.$product->name.' available (on hand 3, reserved 2).');

    expect($second->items()->count())->toBe(0);
});

it('never lets two Pending orders reserve the same last unit', function () {
    $product = $this->part(stock: 1);

    $this->orders()->addPart($this->pendingOrder(), $this->cashierUser, $product->id, 1);

    expect(fn () => $this->orders()->addPart($this->pendingOrder(), $this->cashierUser, $product->id, 1))
        ->toThrow(ValidationException::class);

    expect($product->reservedQty())->toBe(1.0);
});

it('checks only the extra qty when a line is increased, and merges repeat adds', function () {
    $product = $this->part(stock: 4);
    $order = $this->pendingOrder();

    $line = $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);
    $this->orders()->addPart($order, $this->cashierUser, $product->id, 1); // merges into the same line

    expect($order->items()->count())->toBe(1)
        ->and((float) $line->fresh()->qty)->toBe(3.0);

    $this->orders()->updateLine($order, $line->fresh(), $this->cashierUser, ['qty' => 4]);

    expect(fn () => $this->orders()->updateLine($order, $line->fresh(), $this->cashierUser, ['qty' => 5]))
        ->toThrow(ValidationException::class, 'Only 0');

    $this->orders()->updateLine($order, $line->fresh(), $this->cashierUser, ['qty' => 1]);
    expect($product->reservedQty())->toBe(1.0);
});

it('releases the reservation when a line is removed', function () {
    $product = $this->part(stock: 1);
    $order = $this->pendingOrder();
    $line = $this->orders()->addPart($order, $this->cashierUser, $product->id, 1);

    $this->orders()->removeLine($order, $line, $this->cashierUser);

    expect($product->reservedQty())->toBe(0.0)
        ->and($product->availableQty())->toBe(1.0);

    // …so another order can now take it.
    $this->orders()->addPart($this->pendingOrder(), $this->cashierUser, $product->id, 1);
});

it('releases the reservation when a Pending order is voided', function () {
    $product = $this->part(stock: 2);
    $order = $this->pendingOrder();
    $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);

    app(VoidService::class)->void($order, admin(), 'Customer left');

    expect($product->reservedQty())->toBe(0.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(2.0);
});

it('respects whole-number units but allows liters in decimals', function () {
    $piece = $this->part(stock: 5);
    $oil = $this->part(stock: 10, attributes: ['unit' => 'L']);
    $order = $this->pendingOrder();

    expect(fn () => $this->orders()->addPart($order, $this->cashierUser, $piece->id, 1.5))
        ->toThrow(ValidationException::class, 'whole number');

    $this->orders()->addPart($order, $this->cashierUser, $oil->id, 0.8);

    expect($oil->reservedQty())->toBe(0.8);
});

it('can skip the availability check when negative stock is allowed', function () {
    $settings = app(InventorySettings::class);
    $settings->allow_negative_stock = true;
    $settings->save();

    $product = $this->part(stock: 0);
    $order = $this->pendingOrder();

    $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);
    $this->payCash($order);

    expect((float) $product->fresh()->qty_on_hand)->toBe(-2.0);
});

it('does not reserve for non-pending orders', function () {
    $product = $this->part(stock: 5);
    $order = $this->pendingOrder(type: OrderType::Service);
    $this->orders()->addPart($order, $this->cashierUser, $product->id, 2);

    $this->payCash($order);

    expect($product->reservedQty())->toBe(0.0)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(3.0);
});
