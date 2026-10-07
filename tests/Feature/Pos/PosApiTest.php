<?php

use App\Enums\OrderType;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\MotorcycleMake;
use App\Models\Order;
use App\Models\ReprintLog;
use App\Services\Approvals\ManagerPinService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->actingAs($this->cashierUser);
});

describe('cost & profit never reach cashiers or mechanics', function () {
    it('keeps cost out of the product search for everyone', function () {
        $this->part(stock: 3, srp: 150, cost: 90);

        $keys = allKeys($this->getJson('/pos/api/products')->assertOk()->json());

        expect($keys)->not->toContain('avg_cost')->not->toContain('unit_cost')->not->toContain('cost');
    });

    it('hides unit cost, profit and other people\'s commission on orders', function () {
        $mechUser = mechanicUser();
        $mechanic = $this->mechanicFor($mechUser);
        $other = $this->mechanicFor();
        $order = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id, $other->id]]);
        $this->orders()->addPart($order, $this->cashierUser, $this->part(cost: 90)->id, 1);
        $mine = $this->orders()->addLabor($order, $this->cashierUser, $this->labor(fee: 500)->id, mechanicId: $mechanic->id);
        $theirs = $this->orders()->addLabor($order, $this->cashierUser, $this->labor(fee: 300)->id, mechanicId: $other->id);
        $this->payCash($order);

        $cashierJson = $this->getJson("/pos/api/orders/{$order->id}")->assertOk()->json();
        expect(allKeys($cashierJson))->not->toContain('unit_cost')->not->toContain('profit')->not->toContain('commission');

        $this->get("/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->component('orders/show')
            ->missing('order.profit')
            ->missing('order.items.0.unit_cost'));

        $this->actingAs($mechUser);
        $mechanicJson = $this->getJson("/pos/api/orders/{$order->id}")->assertOk()->json();
        $items = collect($mechanicJson['items'])->keyBy('id');

        expect(allKeys($mechanicJson))->not->toContain('unit_cost')->not->toContain('profit')
            ->and($items[$mine->id])->toHaveKey('commission')
            ->and($items[$theirs->id])->not->toHaveKey('commission');

        $this->actingAs(admin());
        $adminJson = $this->getJson("/pos/api/orders/{$order->id}")->assertOk()->json();
        expect($adminJson)->toHaveKey('profit')
            ->and($adminJson['items'][0])->toHaveKey('unit_cost');
    });

    it('does not share cost through the POS page props', function () {
        $this->part(cost: 90);

        $this->get('/pos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('pos/index')
            ->where('auth.can.viewCost', false)
            ->has('services')
            ->has('packages'));

        expect(allKeys($this->get('/pos', ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request())])->json()))
            ->not->toContain('avg_cost')->not->toContain('unit_cost');
    });
});

it('renders every front-of-house screen for the right roles', function () {
    $order = $this->pendingOrder(type: OrderType::Service);
    [$customer] = $this->customerWithBike();

    $this->get('/pos')->assertOk()->assertInertia(fn (Assert $p) => $p->component('pos/index')->where('mode', 'pos'));
    $this->get("/pos?order={$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.id', $order->id));
    $this->get('/orders/open')->assertInertia(fn (Assert $p) => $p->component('orders/open')->has('orders', 1));
    $this->get('/service-board')->assertInertia(fn (Assert $p) => $p->component('service-board/index')->has('jobs', 1));
    $this->get("/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->component('orders/show')->has('timeline'));
    $this->get('/stock-lookup')->assertInertia(fn (Assert $p) => $p->component('stock-lookup'));
    $this->get("/customers/{$customer->id}")->assertInertia(fn (Assert $p) => $p->component('customers/show')->has('motorcycles', 1));
    $this->get('/my-summary')->assertInertia(fn (Assert $p) => $p->component('my-summary')->whereNot('cashier', null));

    // Mechanics: job sheet for their own job only, never the POS.
    $mechUser = mechanicUser();
    $mine = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$this->mechanicFor($mechUser)->id]]);
    $this->actingAs($mechUser);
    $this->get('/pos')->assertForbidden();
    $this->get("/jobs/{$mine->id}")->assertInertia(fn (Assert $p) => $p->component('pos/index')->where('mode', 'job'));
    $this->get("/jobs/{$order->id}")->assertForbidden();
    $this->get('/service-board')->assertInertia(fn (Assert $p) => $p->has('jobs', 1));
});

it('runs a service order end to end through the API', function () {
    [$customer, $bike] = $this->customerWithBike();
    $product = $this->part(stock: 5, srp: 200);
    $service = $this->labor(fee: 300);

    $order = $this->postJson('/pos/api/orders', [
        'type' => 'service', 'customer_id' => $customer->id, 'customer_motorcycle_id' => $bike->id,
        'odometer_in' => 15000, 'complaint' => 'PMS',
    ])->assertCreated()->json();

    $this->postJson("/pos/api/orders/{$order['id']}/items", ['type' => 'part', 'product_id' => $product->id, 'qty' => 2])->assertOk();
    $json = $this->postJson("/pos/api/orders/{$order['id']}/items", ['type' => 'labor', 'service_id' => $service->id])->assertOk()->json();

    expect($json['total'])->toEqual(700)
        ->and(collect($json['items'])->firstWhere('line_type', 'part')['max_qty'])->toEqual(5);

    $partId = collect($json['items'])->firstWhere('line_type', 'part')['id'];
    $this->patchJson("/pos/api/orders/{$order['id']}/items/{$partId}", ['qty' => 1])->assertOk()->assertJsonPath('total', 500);

    $this->putJson("/pos/api/orders/{$order['id']}/service-status", ['service_status' => 'done'])->assertOk()->assertJsonPath('service_status', 'done');

    $paid = $this->postJson("/pos/api/orders/{$order['id']}/pay", ['payment_method_id' => $this->cash->id, 'amount_tendered' => 1000])
        ->assertOk()->json();

    expect($paid['status'])->toBe('paid')
        ->and($paid['slip_no'])->toBe('OS-000001')
        ->and($paid['payments'][0]['change_due'])->toEqual(500);

    $this->patchJson("/pos/api/orders/{$order['id']}/items/{$partId}", ['qty' => 2])->assertUnprocessable()->assertJsonValidationErrors('order');
});

it('asks for a manager PIN through a 422 on "pin" and accepts it on retry', function () {
    $manager = admin();
    app(ManagerPinService::class)->setPin($manager, '5150');
    $product = $this->part(stock: 3, srp: 1000);
    $cart = [['type' => 'part', 'product_id' => $product->id, 'qty' => 1, 'discount_type' => 'percent', 'discount_value' => 30]];

    $this->postJson('/pos/api/checkout', ['lines' => $cart, 'payment_method_id' => $this->cash->id, 'amount_tendered' => 700])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pin');

    $this->postJson('/pos/api/checkout', ['lines' => $cart, 'payment_method_id' => $this->cash->id, 'amount_tendered' => 700, 'pin' => '5150'])
        ->assertCreated()
        ->assertJsonPath('total', 700);
});

it('saves a discounted walk-in cart as Pending only with a PIN', function () {
    $product = $this->part(stock: 3, srp: 1000);
    $lines = [['type' => 'part', 'product_id' => $product->id, 'qty' => 1, 'discount_type' => 'percent', 'discount_value' => 50]];

    $this->postJson('/pos/api/orders', ['type' => 'walk_in', 'lines' => $lines])->assertJsonValidationErrors('pin');
    expect(Order::count())->toBe(0);
});

it('blocks API actions by role', function () {
    $order = $this->pendingOrder();

    $this->actingAs(mechanicUser());
    $this->postJson('/pos/api/checkout', ['lines' => [['type' => 'labor', 'service_id' => $this->labor()->id]]])->assertForbidden();
    $this->postJson('/pos/api/orders', ['type' => 'walk_in'])->assertForbidden();
    $this->postJson("/pos/api/orders/{$order->id}/items", ['type' => 'labor', 'service_id' => $this->labor()->id])->assertForbidden();

    $this->actingAs(clerk());
    $this->getJson('/pos/api/products')->assertOk(); // stock is viewable
    $this->postJson("/pos/api/orders/{$order->id}/pay", ['payment_method_id' => $this->cash->id])->assertForbidden();
});

it('prints the slip and logs reprints', function () {
    $order = $this->pendingOrder();
    $this->orders()->addPart($order, $this->cashierUser, $this->part(srp: 250)->id, 2);
    $this->payCash($order, tendered: 1000);

    $this->get("/print/slip/{$order->id}")
        ->assertOk()
        ->assertSee('OS-000001')
        ->assertSee('This is not an official receipt.')
        ->assertSee('₱500.00', false)
        ->assertDontSee('REPRINT');

    expect(ReprintLog::count())->toBe(0);

    $this->get("/print/slip/{$order->id}?reprint=1")->assertOk()->assertSee('REPRINT');

    expect(ReprintLog::where('document', 'slip')->where('user_id', $this->cashierUser->id)->count())->toBe(1);
});

it('prints a job ticket for service orders only', function () {
    $service = $this->pendingOrder(type: OrderType::Service, attributes: ['complaint' => 'Noisy chain']);
    $walkIn = $this->pendingOrder();

    $this->get("/print/job-ticket/{$service->id}")->assertOk()->assertSee('Noisy chain');
    $this->get("/print/job-ticket/{$walkIn->id}")->assertNotFound();
    $this->get("/print/slip/{$service->id}")->assertNotFound(); // not paid yet
});

it('quick-adds a customer with a motorcycle and finds them by plate', function () {
    $model = MotorcycleMake::create(['name' => 'Honda'])->models()->create(['name' => 'Click 125']);

    $created = $this->postJson('/pos/api/customers', [
        'name' => 'Rico Blanco', 'mobile' => '0917 000 0000',
        'motorcycle' => ['plate_no' => 'xyz  987', 'motorcycle_model_id' => $model->id],
    ])->assertCreated()->json();

    expect($created['motorcycles'][0]['plate_no'])->toBe('XYZ 987');

    $this->getJson('/pos/api/customers?q=xyz')->assertOk()->assertJsonPath('0.name', 'Rico Blanco');

    $this->postJson('/pos/api/customers', ['name' => 'Dup', 'motorcycle' => ['plate_no' => 'XYZ 987']])
        ->assertJsonValidationErrors('motorcycle.plate_no');
});
