<?php

use App\Enums\OrderStatus;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\ReturnsRelationManager;
use App\Filament\Widgets\DefectiveAwaiting;
use App\Filament\Widgets\LowStockList;
use App\Filament\Widgets\SalesByCategoryChart;
use App\Filament\Widgets\SalesByDayChart;
use App\Filament\Widgets\SalesByMethodChart;
use App\Filament\Widgets\ShopStats;
use App\Filament\Widgets\StaleServiceOrders;
use App\Filament\Widgets\TopSellingParts;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Orders\ReturnService;
use Filament\Actions\CreateAction;
use Livewire\Livewire;
use Tests\Feature\Orders\OrderTestHelpers;

uses(OrderTestHelpers::class);

beforeEach(function () {
    $this->setUpOrderFixtures();
    $this->admin = admin();

    $this->product = $this->part(stock: 10, srp: 300, cost: 180);
    $this->order = $this->pendingOrder();
    $this->line = $this->orders()->addPart($this->order, $this->cashierUser, $this->product->id, 2);
    $this->payCash($this->order);
    $this->order->refresh();

    $this->return = app(ReturnService::class)->process($this->order, $this->admin, [
        'reason' => 'wrong_part',
        'resolution' => 'refund',
        'refund_method_id' => $this->cash->id,
        'items' => [['order_item_id' => $this->line->id, 'qty' => 1, 'disposition' => 'back_to_stock']],
    ]);
});

it('opens the sales and expense screens for admins only', function () {
    $paths = ['/admin', '/admin/orders', '/admin/orders/'.$this->order->id, '/admin/returns',
        '/admin/returns/'.$this->return->id, '/admin/payments', '/admin/expenses', '/admin/reports'];

    foreach ($paths as $path) {
        $this->actingAs($this->admin)->get($path)->assertOk();
    }

    foreach (array_slice($paths, 1, 6) as $path) {
        $this->actingAs(clerk())->get($path)->assertForbidden();
    }

    $this->actingAs(clerk())->get('/admin')->assertOk();
    $this->actingAs(cashier())->get('/admin/orders')->assertForbidden();
});

it('shows an order with its lines, payment and return; cost only for admins', function () {
    $this->actingAs($this->admin)->get('/admin/orders/'.$this->order->id)
        ->assertOk()
        ->assertSee($this->order->slip_no);

    // Relation managers load lazily, so test them directly.
    Livewire::test(ReturnsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => ViewOrder::class])
        ->assertSee($this->return->return_no);

    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => ViewOrder::class])
        ->assertSee($this->product->name)
        ->assertSee('Unit cost')
        ->assertSee('1 returned');

    $this->actingAs($this->admin)->get('/admin/returns/'.$this->return->id)
        ->assertOk()
        ->assertSee($this->order->slip_no)
        ->assertSee('Money given back');
});

it('voids a same-day paid order from the back-office', function () {
    $order = $this->pendingOrder();
    $this->orders()->addPart($order, $this->cashierUser, $this->product->id, 1);
    $this->payCash($order);

    $this->actingAs($this->admin);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('void', ['reason' => 'Wrong customer'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Voided);
});

it('records expenses and voids them instead of deleting', function () {
    $this->actingAs($this->admin);
    $category = ExpenseCategory::create(['name' => 'Utilities']);

    Livewire::test(ManageExpenses::class)
        ->callAction(CreateAction::class, [
            'date' => today()->toDateString(),
            'expense_category_id' => $category->id,
            'description' => 'Meralco bill',
            'amount' => 3450.75,
        ])
        ->assertHasNoActionErrors();

    $expense = Expense::sole();
    expect($expense->created_by)->toBe($this->admin->id)
        ->and((float) $expense->amount)->toBe(3450.75);

    Livewire::test(ManageExpenses::class)
        ->assertTableActionDoesNotExist('delete')
        ->callTableAction('void', $expense, ['reason' => 'Entered twice'])
        ->assertHasNoTableActionErrors();

    expect($expense->fresh()->isVoided())->toBeTrue()
        ->and($expense->fresh()->void_reason)->toBe('Entered twice')
        ->and($this->admin->can('update', $expense->fresh()))->toBeFalse()
        ->and(clerk()->can('viewAny', Expense::class))->toBeFalse();
});

describe('dashboard', function () {
    it('shows sales, profit and inventory value to admins', function () {
        $this->actingAs($this->admin);

        Livewire::test(ShopStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('Sales today')
            ->assertSee('₱600.00')
            ->assertSee('Net sales after refunds')
            ->assertSee('₱300.00')
            ->assertSee('Gross profit')
            ->assertSee('Inventory value')
            ->assertSee('Pending orders');

        foreach ([SalesByDayChart::class, SalesByMethodChart::class, SalesByCategoryChart::class] as $chart) {
            Livewire::test($chart, ['pageFilters' => ['period' => 'month']])->assertOk();
        }

        foreach ([TopSellingParts::class, LowStockList::class, StaleServiceOrders::class, DefectiveAwaiting::class] as $table) {
            Livewire::test($table, ['pageFilters' => ['period' => 'week']])->assertOk();
        }

        Livewire::test(TopSellingParts::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee($this->product->name);
    });

    it('shows clerks stock only, never money or profit', function () {
        $this->actingAs(clerk());

        Livewire::test(ShopStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('Low stock')
            ->assertSee('Defective awaiting action')
            ->assertDontSee('Gross profit')
            ->assertDontSee('Inventory value')
            ->assertDontSee('Sales today');

        expect(SalesByDayChart::canView())->toBeFalse()
            ->and(TopSellingParts::canView())->toBeFalse()
            ->and(LowStockList::canView())->toBeTrue();
    });
});
