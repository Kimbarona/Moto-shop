<?php

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\MotorcycleMake;
use App\Models\Product;
use App\Models\Unit;
use Livewire\Livewire;

function makeProduct(array $attributes = []): Product
{
    $unit = Unit::firstOrCreate(['abbreviation' => 'pc'], ['name' => 'Piece']);

    return Product::create(['name' => 'Spark Plug', 'unit_id' => $unit->id, 'srp' => 150, 'avg_cost' => 95, ...$attributes]);
}

it('auto-assigns sequential SKUs', function () {
    expect(makeProduct()->sku)->toBe('P-000001')
        ->and(makeProduct()->sku)->toBe('P-000002')
        ->and(makeProduct(['sku' => 'CUSTOM-1'])->sku)->toBe('CUSTOM-1');
});

it('does not allow qty_on_hand to be mass-assigned', function () {
    $product = makeProduct(['qty_on_hand' => 99]);

    expect((float) $product->fresh()->qty_on_hand)->toBe(0.0);
});

it('finds parts that fit a model, plus universal parts', function () {
    $honda = MotorcycleMake::create(['name' => 'Honda']);
    $click = $honda->models()->create(['name' => 'Click 125']);
    $beat = $honda->models()->create(['name' => 'Beat']);

    $clickBelt = makeProduct(['name' => 'Click belt']);
    $clickBelt->fitsModels()->attach($click);
    $beatPad = makeProduct(['name' => 'Beat pad']);
    $beatPad->fitsModels()->attach($beat);
    $oil = makeProduct(['name' => 'Oil', 'is_universal' => true]);

    $names = Product::fitsModel($click->id)->pluck('name')->all();

    expect($names)->toContain('Click belt', 'Oil')->not->toContain('Beat pad');
});

it('computes reserved and available quantities', function () {
    $product = makeProduct();

    $row = Product::withStockLevels()->find($product->id);

    expect((float) $row->reserved_qty)->toBe(0.0)
        ->and((float) $row->available_qty)->toBe(0.0)
        ->and($product->availableQty())->toBe(0.0);
});

it('lets an admin edit SRP and cost', function () {
    $product = makeProduct();
    $this->actingAs(admin());

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['srp' => 175, 'avg_cost' => 100])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh())->srp->toEqual('175.00')->avg_cost->toEqual('100.0000');
});

it('does not let an inventory clerk change SRP or cost, even by tampering', function () {
    $product = makeProduct();
    $this->actingAs(clerk());

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldIsDisabled('srp')
        ->assertFormFieldIsHidden('avg_cost')
        ->set('data.srp', 1)
        ->set('data.avg_cost', 1)
        ->set('data.name', 'Spark Plug NGK')
        ->call('save');

    expect($product->fresh())
        ->name->toBe('Spark Plug NGK')
        ->srp->toEqual('150.00')
        ->avg_cost->toEqual('95.0000');
});

it('creates a part with fitments from the back-office', function () {
    $model = MotorcycleMake::create(['name' => 'Yamaha'])->models()->create(['name' => 'NMAX']);
    $unit = Unit::create(['name' => 'Piece', 'abbreviation' => 'pc']);
    $this->actingAs(clerk());

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Drive Belt NMAX',
            'unit_id' => $unit->id,
            'reorder_level' => 2,
            'is_universal' => false,
            'fitments' => [['motorcycle_model_id' => $model->id, 'notes' => null]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Drive Belt NMAX')->first();

    expect($product->sku)->toStartWith('P-')
        ->and($product->fitsModels->pluck('id')->all())->toBe([$model->id]);
});
