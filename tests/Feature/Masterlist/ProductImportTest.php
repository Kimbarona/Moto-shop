<?php

use App\Exports\ProductsExport;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\MotorcycleMake;
use App\Models\MotorcycleModel;
use App\Models\Product;
use App\Models\Unit;
use App\Services\Catalog\ProductImportService;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->admin = admin();
    $this->actingAs($this->admin);
    Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
    $honda = MotorcycleMake::create(['name' => 'Honda']);
    $this->click = MotorcycleModel::create(['motorcycle_make_id' => $honda->id, 'name' => 'Click 125i']);
    $this->import = fn (array $rows, $user = null) => app(ProductImportService::class)->import($rows, $user ?? $this->admin);
});

it('adds new parts, updates by SKU and syncs fitment', function () {
    $existing = stockProduct(['name' => 'Old name', 'srp' => 100]);

    $result = ($this->import)([
        ['sku' => '', 'name' => 'Air Filter Click', 'category' => 'Engine', 'brand' => 'Honda Genuine', 'unit' => 'pc', 'srp' => '420', 'reorder_level' => '3', 'fits' => 'Honda Click 125i', 'universal' => 'no'],
        ['sku' => $existing->sku, 'name' => 'New name', 'srp' => '150', 'bin_location' => 'A-3'],
        ['sku' => '', 'name' => '', 'unit' => ''], // blank row ignored
    ]);

    expect($result)->toMatchArray(['created' => 1, 'updated' => 1, 'skipped' => 0, 'errors' => []]);

    $new = Product::where('name', 'Air Filter Click')->sole();
    expect($new->sku)->not->toBeEmpty()
        ->and($new->category->name)->toBe('Engine')
        ->and($new->brand->name)->toBe('Honda Genuine')
        ->and((float) $new->srp)->toBe(420.0)
        ->and((float) $new->qty_on_hand)->toBe(0.0)
        ->and($new->fitsModels->pluck('id')->all())->toBe([$this->click->id]);

    expect($existing->fresh())->name->toBe('New name')->bin_location->toBe('A-3')
        ->and((float) $existing->fresh()->srp)->toBe(150.0);
});

it('skips bad rows with a reason and keeps the good ones', function () {
    $result = ($this->import)([
        ['name' => 'No unit', 'srp' => '10', 'unit' => 'box'],
        ['name' => 'Bad model', 'srp' => '10', 'unit' => 'pc', 'fits' => 'Yamaha Mio 9000'],
        ['name' => '', 'srp' => '10', 'unit' => 'pc'],
        ['name' => 'Good', 'srp' => '10', 'unit' => 'pc'],
    ]);

    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toBe(3)
        ->and($result['errors'][0])->toContain('Row 2')->toContain('unknown unit')
        ->and($result['errors'][1])->toContain('Yamaha Mio 9000')
        ->and($result['errors'][2])->toContain('name');
});

it('never lets a clerk set prices through an import', function () {
    $existing = stockProduct(['srp' => 100]);

    $result = ($this->import)([
        ['sku' => $existing->sku, 'srp' => '1', 'bin_location' => 'B-1'],
        ['name' => 'Clerk part', 'unit' => 'pc', 'srp' => '999'],
    ], clerk());

    expect((float) $existing->fresh()->srp)->toBe(100.0)
        ->and($existing->fresh()->bin_location)->toBe('B-1')
        ->and((float) Product::where('name', 'Clerk part')->sole()->srp)->toBe(0.0)
        ->and($result['errors'])->toHaveCount(2);
});

it('exports products with stock columns, cost only for admins', function () {
    stockProduct(['name' => 'Spark Plug', 'avg_cost' => 55]);

    $export = new ProductsExport(includeCost: true);
    expect($export->headings())->toContain('avg_cost (read only)')->toContain('on_hand (read only)');
    expect((new ProductsExport(includeCost: false))->headings())->not->toContain('avg_cost (read only)');

    Excel::fake();
    Livewire::test(ListProducts::class)->callAction('export');
    Excel::assertDownloaded('products-'.now()->format('Ymd-His').'.xlsx', fn (ProductsExport $e) => in_array('avg_cost (read only)', $e->headings(), true));
});
