<?php

use App\Enums\OrderType;
use App\Filament\Pages\Reports;
use App\Reports\Period;
use App\Reports\ReportRegistry;
use App\Services\Orders\ReturnService;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->admin = admin();

    // A paid service job with a part and labor, and a refund of the part.
    $part = $this->part(stock: 10, srp: 250, cost: 140);
    $mechanic = $this->mechanicFor();
    $order = $this->pendingOrder(type: OrderType::Service, attributes: ['mechanic_ids' => [$mechanic->id]]);
    $line = $this->orders()->addPart($order, $this->cashierUser, $part->id, 2);
    $this->orders()->addLabor($order, $this->cashierUser, $this->labor(fee: 400)->id);
    $this->payCash($order);

    app(ReturnService::class)->process($order->fresh(), $this->admin, [
        'reason' => 'defective',
        'resolution' => 'refund',
        'refund_method_id' => $this->cash->id,
        'items' => [['order_item_id' => $line->id, 'qty' => 1, 'disposition' => 'defective']],
    ]);
});

it('lets admins and clerks in, never cashiers or mechanics', function () {
    $this->actingAs($this->admin)->get('/admin/reports')->assertOk()->assertSee('Daily Sales Summary')->assertSee('Inventory Valuation');
    $this->actingAs(clerk())->get('/admin/reports')->assertOk()
        ->assertSee('Low Stock / Reorder List')
        ->assertDontSee('Daily Sales Summary')
        ->assertDontSee('Inventory Valuation');
    $this->actingAs(cashier())->get('/admin/reports')->assertForbidden();
    $this->actingAs(mechanicUser())->get('/admin/reports')->assertForbidden();
});

it('runs and renders every report for an admin', function () {
    $this->actingAs($this->admin);

    foreach (ReportRegistry::REPORTS as $class) {
        Livewire::test(Reports::class)
            ->set('from', today()->subDays(7)->toDateString())
            ->set('until', today()->toDateString())
            ->call('choose', $class::key())
            ->assertOk()
            ->assertSee($class::title());
    }
});

it('shows the sales figures, with refunds as negative lines', function () {
    $this->actingAs($this->admin);

    Livewire::test(Reports::class, ['report' => 'daily-sales'])
        ->assertSee('Net sales')
        ->assertSee('₱900.00')   // 2 × 250 + 400
        ->assertSee('-₱250.00')  // one part refunded
        ->assertSee('₱650.00')   // net
        ->assertSee('Gross profit');
});

it('keeps cost and profit away from clerks, and stops them opening admin reports', function () {
    $this->actingAs(clerk());

    Livewire::test(Reports::class, ['report' => 'low-stock'])
        ->assertSee('Low Stock / Reorder List')
        ->assertDontSee('Last cost')
        ->assertDontSee('Est. order cost');

    Livewire::test(Reports::class, ['report' => 'defective-stock'])
        ->assertSee('Customer return')
        ->assertDontSee('Value at cost');

    // An admin-only report key falls back to the report list.
    Livewire::test(Reports::class, ['report' => 'gross-profit'])
        ->assertSet('report', null)
        ->assertDontSee('Cost of goods sold');
});

it('exports the same result to Excel and PDF, and logs it', function () {
    $this->actingAs($this->admin);
    $period = Period::today();
    $name = 'daily-sales_'.$period->from->format('Ymd').'_'.$period->until->format('Ymd');

    Livewire::test(Reports::class, ['report' => 'daily-sales', 'from' => today()->toDateString(), 'until' => today()->toDateString()])
        ->call('exportExcel')
        ->assertFileDownloaded($name.'.xlsx');

    Livewire::test(Reports::class, ['report' => 'returns', 'from' => today()->toDateString(), 'until' => today()->toDateString()])
        ->call('exportPdf')
        ->assertFileDownloaded('returns_'.$period->from->format('Ymd').'_'.$period->until->format('Ymd').'.pdf');

    expect(Activity::where('event', 'exported')->count())->toBe(2);
});

it('applies report options', function () {
    $this->actingAs($this->admin);

    Livewire::test(Reports::class, ['report' => 'sales-by-item'])
        ->set('opts.group_by', 'category')
        ->assertSee('Labor')
        ->assertSee('Uncategorized');
});
