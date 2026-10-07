<?php

use App\Enums\DefectiveStatus;
use App\Enums\DocumentStatus;
use App\Filament\Resources\DefectiveStocks\Pages\ManageDefectiveStocks;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\StockCardRelationManager;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\EditStockCount;
use App\Filament\Resources\StockCounts\RelationManagers\CountItemsRelationManager;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockReceivings\Pages\CreateStockReceiving;
use App\Filament\Resources\StockReceivings\Pages\EditStockReceiving;
use App\Models\DefectiveStock;
use App\Models\StockCount;
use App\Models\StockReceiving;
use App\Services\Stock\ReceivingService;
use Livewire\Livewire;

beforeEach(function () {
    $this->clerk = clerk();
    $this->actingAs($this->clerk);
});

it('opens every inventory screen for clerks and admins, never for cashiers', function () {
    $paths = ['/admin/stock-in', '/admin/stock-in/create', '/admin/adjustments', '/admin/adjustments/create',
        '/admin/physical-counts', '/admin/physical-counts/create', '/admin/defective-stock', '/admin/stock-movements'];

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
        $this->actingAs(admin())->get($path)->assertOk();
        $this->actingAs(cashier())->get($path)->assertForbidden();
        $this->actingAs($this->clerk);
    }
});

it('creates a stock-in draft and posts it from the edit page', function () {
    $product = stockProduct();

    Livewire::test(CreateStockReceiving::class)
        ->fillForm([
            'date' => today()->toDateString(),
            'reference_no' => 'DR-77',
            'items' => [['product_id' => $product->id, 'qty' => 6, 'unit_cost' => 110]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $receiving = StockReceiving::firstOrFail();
    expect($receiving->status)->toBe(DocumentStatus::Draft)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(0.0);

    Livewire::test(EditStockReceiving::class, ['record' => $receiving->getRouteKey()])
        ->callAction('post')
        ->assertHasNoActionErrors();

    expect($receiving->fresh()->status)->toBe(DocumentStatus::Posted)
        ->and((float) $product->fresh()->qty_on_hand)->toBe(6.0)
        ->and((float) $product->fresh()->avg_cost)->toBe(110.0);

    // Posted documents are view-only.
    $this->get("/admin/stock-in/{$receiving->id}/edit")->assertForbidden();
    $this->get("/admin/stock-in/{$receiving->id}")->assertOk();
});

it('validates the adjustment direction in the form', function () {
    $product = stockProduct();

    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([
            'date' => today()->toDateString(),
            'reason' => 'damaged',
            'remarks' => 'Cracked',
            'items' => [['product_id' => $product->id, 'qty' => 2]],
        ])
        ->call('create')
        ->assertHasFormErrors();

    Livewire::test(CreateStockAdjustment::class)
        ->fillForm([
            'date' => today()->toDateString(),
            'reason' => 'damaged',
            'remarks' => '',
            'items' => [['product_id' => $product->id, 'qty' => -2]],
        ])
        ->call('create')
        ->assertHasFormErrors(['remarks' => 'required']);
});

it('runs a physical count from the count sheet', function () {
    $product = stockProduct();
    $receiving = app(ReceivingService::class)->createDraft(['date' => today()->toDateString()], [['product_id' => $product->id, 'qty' => 10, 'unit_cost' => 5]], $this->clerk);
    app(ReceivingService::class)->post($receiving, $this->clerk);

    Livewire::test(CreateStockCount::class)
        ->fillForm(['date' => today()->toDateString()])
        ->call('create')
        ->assertHasNoFormErrors();

    $count = StockCount::firstOrFail();
    $item = $count->items()->firstOrFail();

    Livewire::test(CountItemsRelationManager::class, ['ownerRecord' => $count, 'pageClass' => EditStockCount::class])
        ->assertCanSeeTableRecords([$item])
        ->call('updateTableColumnState', 'counted_qty', (string) $item->getKey(), '7');

    expect((float) $item->fresh()->counted_qty)->toBe(7.0);

    Livewire::test(EditStockCount::class, ['record' => $count->getRouteKey()])->callAction('post');

    expect((float) $product->fresh()->qty_on_hand)->toBe(7.0)
        ->and($count->fresh()->status)->toBe(DocumentStatus::Posted);
});

it('pulls a defective part off the shelf and writes it off', function () {
    $product = stockProduct();
    $receiving = app(ReceivingService::class)->createDraft(['date' => today()->toDateString()], [['product_id' => $product->id, 'qty' => 3, 'unit_cost' => 5]], $this->clerk);
    app(ReceivingService::class)->post($receiving, $this->clerk);

    Livewire::test(ManageDefectiveStocks::class)
        ->callAction('fromShelf', data: ['product_id' => $product->id, 'qty' => 1, 'notes' => 'Bent'])
        ->assertHasNoActionErrors();

    $defective = DefectiveStock::firstOrFail();
    expect((float) $product->fresh()->qty_on_hand)->toBe(2.0);

    Livewire::test(ManageDefectiveStocks::class)
        ->callTableAction('writeOff', $defective, data: ['notes' => 'Scrap'])
        ->assertHasNoTableActionErrors();

    expect($defective->fresh()->status)->toBe(DefectiveStatus::WrittenOff);
});

it('shows the stock card without cost to clerks, with cost to admins, and exports it', function () {
    $product = stockProduct();
    $receiving = app(ReceivingService::class)->createDraft(['date' => today()->toDateString()], [['product_id' => $product->id, 'qty' => 3, 'unit_cost' => 5]], $this->clerk);
    app(ReceivingService::class)->post($receiving, $this->clerk);

    Livewire::test(StockCardRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->assertCanSeeTableRecords($product->stockMovements)
        ->assertTableColumnHidden('unit_cost')
        ->callTableAction('export')
        ->assertFileDownloaded();

    $this->actingAs(admin());

    Livewire::test(StockCardRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->assertTableColumnVisible('unit_cost');

    Livewire::test(ListStockMovements::class)
        ->callAction('export')
        ->assertFileDownloaded();
});
