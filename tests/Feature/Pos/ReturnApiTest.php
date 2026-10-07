<?php

use App\Models\ReprintLog;
use App\Models\SalesReturn;
use App\Services\Approvals\ManagerPinService;
use App\Services\Orders\ReturnService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);

    $this->manager = admin();
    app(ManagerPinService::class)->setPin($this->manager, '4826');

    $this->product = $this->part(stock: 10, srp: 250, cost: 140);
    $this->order = $this->pendingOrder();
    $this->line = $this->orders()->addPart($this->order, $this->cashierUser, $this->product->id, 2);
    $this->payCash($this->order);
    $this->order->refresh();

    $this->payload = fn (array $extra = []) => [
        'order_id' => $this->order->id,
        'reason' => 'wrong_part',
        'resolution' => 'refund',
        'refund_method_id' => $this->cash->id,
        'items' => [['order_item_id' => $this->line->id, 'qty' => 1, 'disposition' => 'back_to_stock']],
        ...$extra,
    ];
});

it('shows the Returns screen to cashiers and admins only', function () {
    $this->get('/returns/new')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('returns/new')->where('order', null)->has('reasons', 4));

    $this->actingAs(admin())->get('/returns/new?order='.$this->order->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.slip_no', $this->order->slip_no)->has('order.items', 1));

    $this->actingAs(mechanicUser())->get('/returns/new')->assertForbidden();
    $this->actingAs(clerk())->get('/returns/new')->assertForbidden();
    $this->actingAs(mechanicUser())->postJson('/pos/api/returns', ($this->payload)())->assertForbidden();
});

it('finds paid orders by slip no., customer and plate, without cost', function () {
    [$customer, $bike] = $this->customerWithBike();
    $service = $this->pendingOrder(attributes: ['customer_id' => $customer->id, 'customer_motorcycle_id' => $bike->id]);
    $this->orders()->addPart($service, $this->cashierUser, $this->product->id, 1);
    $this->payCash($service);
    $service->refresh();

    // Unpaid orders are never listed.
    $this->pendingOrder();

    $ids = fn (string $q) => collect($this->getJson('/pos/api/returns/orders?q='.urlencode($q))->assertOk()->json())->pluck('id')->all();

    expect($ids($this->order->slip_no))->toBe([$this->order->id])
        ->and($ids($customer->name))->toBe([$service->id])
        ->and($ids(str_replace(' ', '', $bike->plate_no)))->toBe([$service->id])
        ->and($ids(''))->toBe([$service->id, $this->order->id]);

    $keys = allKeys($this->getJson('/pos/api/returns/orders/'.$this->order->id)->assertOk()->json());
    expect($keys)->not->toContain('unit_cost')->not->toContain('avg_cost')->not->toContain('commission')->not->toContain('profit');
});

it('asks a cashier for the manager PIN, then processes the return', function () {
    $this->postJson('/pos/api/returns', ($this->payload)())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');

    $response = $this->postJson('/pos/api/returns', ($this->payload)(['pin' => '4826']))
        ->assertCreated()
        ->assertJson(['return_no' => 'RET-000001', 'resolution' => 'refund', 'return_total' => 250, 'refund_amount' => 250]);

    expect(allKeys($response->json()))->not->toContain('unit_cost')
        ->and((float) $this->product->fresh()->qty_on_hand)->toBe(9.0)
        ->and($this->order->fresh()->refund_status->value)->toBe('partial');

    $this->postJson('/pos/api/returns', ($this->payload)(['pin' => '4826', 'items' => [
        ['order_item_id' => $this->line->id, 'qty' => 5, 'disposition' => 'back_to_stock'],
    ]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');
});

it('prints a return slip, marking and logging reprints', function () {
    $return = app(ReturnService::class)->process($this->order, $this->manager, [
        'reason' => 'defective',
        'resolution' => 'refund',
        'refund_method_id' => $this->cash->id,
        'items' => [['order_item_id' => $this->line->id, 'qty' => 1, 'disposition' => 'defective']],
    ]);

    $this->get('/print/return-slip/'.$return->id)->assertOk()
        ->assertSee('Return Slip')
        ->assertSee($return->return_no)
        ->assertSee($this->order->slip_no)
        ->assertSee('Defective')
        ->assertDontSee('REPRINT');

    $this->get('/print/return-slip/'.$return->id.'?reprint=1')->assertOk()->assertSee('REPRINT');

    expect(ReprintLog::where('printable_type', $return->getMorphClass())->where('printable_id', $return->id)->count())->toBe(1);

    $this->actingAs(mechanicUser())->get('/print/return-slip/'.$return->id)->assertForbidden();
});

it('carries an unused exchange credit into the POS and refuses used ones', function () {
    $exchange = app(ReturnService::class)->process($this->order, $this->manager, [
        'reason' => 'wrong_part',
        'resolution' => 'exchange',
        'items' => [['order_item_id' => $this->line->id, 'qty' => 1, 'disposition' => 'back_to_stock']],
    ]);

    $this->get('/pos?exchange='.$exchange->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('exchange.return_no', $exchange->return_no)->where('exchange.credit', 250));

    $this->postJson('/pos/api/checkout', [
        'lines' => [['type' => 'part', 'product_id' => $this->product->id, 'qty' => 2]],
        'exchange_return_id' => $exchange->id,
        'payment_method_id' => $this->cash->id,
        'amount_tendered' => 250,
    ])->assertCreated()->assertJson(['return_credit' => 250, 'amount_due' => 250]);

    $this->get('/pos?exchange='.$exchange->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('exchange', null));

    expect(SalesReturn::unusedExchange()->count())->toBe(0);
});

it('lists returns on the order page with a return ability', function () {
    $this->get('/orders/'.$this->order->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.can.return', true)->has('returns', 0));

    app(ReturnService::class)->process($this->order, $this->manager, [
        'reason' => 'wrong_part',
        'resolution' => 'refund',
        'refund_method_id' => $this->cash->id,
        'items' => [['order_item_id' => $this->line->id, 'qty' => 2, 'disposition' => 'back_to_stock']],
    ]);

    $this->get('/orders/'.$this->order->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.can.return', false)
            ->where('order.refund_status', 'full')
            ->has('returns', 1)
            ->where('timeline', fn ($timeline) => collect($timeline)->contains(fn ($e) => str_starts_with($e['text'], 'Return RET-000001'))
                // The approval shows once (from manager_approvals), not again from the audit log.
                && collect($timeline)->where('event', 'approved')->count() === 1));
});
