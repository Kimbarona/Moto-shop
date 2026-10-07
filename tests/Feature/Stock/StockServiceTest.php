<?php

use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use App\Settings\InventorySettings;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->stock = app(StockService::class);
    $this->actingAs(admin());
});

it('updates qty_on_hand and balance_after on every movement', function () {
    $product = stockProduct();

    $in = stockTx(fn () => $this->stock->stockIn($product, 10, 100));
    $out = stockTx(fn () => $this->stock->move($product, MovementType::Sale, -3));
    $adj = stockTx(fn () => $this->stock->move($product, MovementType::AdjustmentIn, 1.5));

    expect([(float) $in->balance_after, (float) $out->balance_after, (float) $adj->balance_after])->toBe([10.0, 7.0, 8.5])
        ->and((float) $product->fresh()->qty_on_hand)->toBe(8.5)
        ->and((float) StockMovement::where('product_id', $product->id)->sum('qty'))->toBe(8.5);
});

it('records the movement details', function () {
    $product = stockProduct();
    $user = auth()->user();

    $movement = stockTx(fn () => $this->stock->stockIn($product, 4, 120.5, $product, 'Opening'));

    expect($movement)
        ->type->toBe(MovementType::StockIn)
        ->qty->toEqual('4.00')
        ->unit_cost->toEqual('120.5000')
        ->remarks->toBe('Opening')
        ->user_id->toBe($user->id)
        ->reference_type->toBe($product->getMorphClass());
});

it('computes the weighted average cost on stock in', function () {
    $product = stockProduct();

    stockTx(fn () => $this->stock->stockIn($product, 10, 100)); // 10 @ 100
    stockTx(fn () => $this->stock->stockIn($product, 5, 130));  // (10×100 + 5×130) / 15 = 110

    expect((float) $product->fresh()->avg_cost)->toBe(110.0);

    stockTx(fn () => $this->stock->move($product, MovementType::Sale, -15)); // empty shelf
    stockTx(fn () => $this->stock->stockIn($product, 2, 150));               // nothing to average with

    expect((float) $product->fresh()->avg_cost)->toBe(150.0);
});

it('keeps four decimals of average cost', function () {
    $product = stockProduct();

    stockTx(fn () => $this->stock->stockIn($product, 3, 10));
    stockTx(fn () => $this->stock->stockIn($product, 3, 10.01));

    expect($product->fresh()->avg_cost)->toEqual('10.0050');
});

it('does not change average cost on outgoing or adjustment movements', function () {
    $product = stockProduct();
    stockTx(fn () => $this->stock->stockIn($product, 10, 100));

    stockTx(fn () => $this->stock->move($product, MovementType::AdjustmentIn, 5, 999));

    expect((float) $product->fresh()->avg_cost)->toBe(100.0);
});

it('blocks negative stock unless the setting allows it', function () {
    $product = stockProduct();
    stockTx(fn () => $this->stock->stockIn($product, 2, 50));

    expect(fn () => stockTx(fn () => $this->stock->move($product, MovementType::Sale, -3)))
        ->toThrow(ValidationException::class, 'Not enough stock for Oil Filter: 2 on hand, 3 needed.');

    expect((float) $product->fresh()->qty_on_hand)->toBe(2.0)
        ->and(StockMovement::count())->toBe(1);

    $settings = app(InventorySettings::class);
    $settings->allow_negative_stock = true;
    $settings->save();

    stockTx(fn () => $this->stock->move($product, MovementType::Sale, -3));

    expect((float) $product->fresh()->qty_on_hand)->toBe(-1.0);
});

it('handles decimal quantities (liters, meters)', function () {
    $product = stockProduct(['name' => 'Bulk Oil']);

    stockTx(fn () => $this->stock->stockIn($product, 20, 255));
    stockTx(fn () => $this->stock->move($product, MovementType::Sale, -0.8));

    expect((float) $product->fresh()->qty_on_hand)->toBe(19.2);
});

it('refuses any other write to qty_on_hand', function () {
    $product = stockProduct();

    expect(function () use ($product) {
        $product->qty_on_hand = 99;
        $product->save();
    })->toThrow(LogicException::class, 'StockService');

    expect((float) $product->fresh()->qty_on_hand)->toBe(0.0);
});

it('rolls the cache and the ledger back together', function () {
    $product = stockProduct();

    try {
        stockTx(function () use ($product) {
            $this->stock->stockIn($product, 5, 10);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect((float) $product->fresh()->qty_on_hand)->toBe(0.0)
        ->and(StockMovement::count())->toBe(0);
});

it('logs cost changes from stock in to the audit log', function () {
    $product = stockProduct(['avg_cost' => 100]);
    stockTx(fn () => $this->stock->stockIn($product, 1, 200));

    $log = $product->activities()->latest('id')->first();

    expect($log->properties['old']['avg_cost'])->toEqual('100.0000')
        ->and($log->properties['attributes']['avg_cost'])->toEqual('200.0000');
});
