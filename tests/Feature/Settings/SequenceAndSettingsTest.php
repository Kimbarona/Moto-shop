<?php

use App\Filament\Pages\AppSettings;
use App\Services\Numbering\SequenceService;
use App\Settings\PosSettings;
use App\Settings\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->sequences = app(SequenceService::class);
});

it('hands out sequential, zero-padded numbers', function () {
    expect($this->sequences->next('order'))->toBe('ORD-000001')
        ->and($this->sequences->next('order'))->toBe('ORD-000002')
        ->and($this->sequences->next('return'))->toBe('RET-000001');
});

it('does not leave a gap when the surrounding transaction rolls back', function () {
    $this->sequences->next('slip'); // OS-000001

    try {
        DB::transaction(function () {
            $this->sequences->next('slip'); // would be OS-000002…
            throw new RuntimeException('payment failed');
        });
    } catch (RuntimeException) {
    }

    expect($this->sequences->next('slip'))->toBe('OS-000002');
});

it('never lets the slip number move backward', function () {
    foreach (range(1, 5) as $i) {
        $this->sequences->next('slip');
    }

    expect(fn () => $this->sequences->setNextNumber('slip', 3))
        ->toThrow(ValidationException::class, 'cannot be lower');

    $this->sequences->setNextNumber('slip', 100);

    expect($this->sequences->next('slip'))->toBe('OS-000100');
});

it('rejects a lower slip number from the settings page', function () {
    foreach (range(1, 10) as $i) {
        $this->sequences->next('slip');
    }

    $this->actingAs(superAdmin());

    Livewire::test(AppSettings::class)
        ->set('data.seq.slip.next_number', 5)
        ->call('save')
        ->assertHasErrors();

    expect($this->sequences->nextNumber('slip'))->toBe(11);
});

it('saves settings and audit-logs the before/after values', function () {
    $this->actingAs(superAdmin());

    Livewire::test(AppSettings::class)
        ->set('data.shop.shop_name', 'Pit Stop Moto Parts')
        ->set('data.pos.cashier_max_discount_percent', 7.5)
        ->set('data.seq.slip.prefix', 'SLIP-')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(ShopSettings::class)->refresh()->shop_name)->toBe('Pit Stop Moto Parts')
        ->and(app(PosSettings::class)->refresh()->cashier_max_discount_percent)->toBe(7.5)
        ->and($this->sequences->peek('slip'))->toBe('SLIP-000001');

    $log = Activity::where('log_name', 'Settings')->latest('id')->first();

    expect($log->properties['old']['shop.shop_name'])->toBe('Moto Shop')
        ->and($log->properties['attributes']['shop.shop_name'])->toBe('Pit Stop Moto Parts')
        ->and($log->properties['attributes'])->toHaveKey('seq.slip.prefix');
});

it('shows settings read-only to admins and hides them from everyone else', function () {
    $this->actingAs(admin());
    $this->get('/admin/settings')->assertOk();

    Livewire::test(AppSettings::class)
        ->set('data.shop.shop_name', 'Hacked')
        ->call('save')
        ->assertForbidden();

    expect(app(ShopSettings::class)->refresh()->shop_name)->toBe('Moto Shop');

    $this->actingAs(cashier())->get('/admin/settings')->assertForbidden();
});
