<?php

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Support\AuditLog;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = admin();
    $this->actingAs($this->admin);

    $this->product = stockProduct(['sku' => 'OF-001', 'srp' => 180]);
    $this->product->update(['srp' => 195, 'reorder_level' => 4]);

    $this->created = Activity::where('log_name', 'Product')->where('event', 'created')->latest('id')->first();
    $this->priceChange = Activity::where('log_name', 'Product')->where('event', 'updated')->latest('id')->first();

    $this->void = activity('Order')->causedBy($this->admin)->event('voided')
        ->withProperties(['reason' => 'Wrong customer'])->log('Order ORD-000009 voided');
});

it('is open to admins only and has no create, edit or delete', function () {
    $this->get('/admin/audit-log')->assertOk()->assertSee('OF-001');
    $this->get('/admin/audit-log/'.$this->priceChange->id)->assertOk();

    $this->actingAs(superAdmin())->get('/admin/audit-log')->assertOk();

    foreach ([cashier(), mechanicUser(), clerk()] as $user) {
        $this->actingAs($user)->get('/admin/audit-log')->assertForbidden();
    }

    $this->actingAs($this->admin);

    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit($this->priceChange))->toBeFalse()
        ->and(AuditLogResource::canDelete($this->priceChange))->toBeFalse()
        ->and(AuditLogResource::hasPage('edit'))->toBeFalse();
});

it('shows who changed what, with before and after values and the IP', function () {
    expect($this->priceChange->properties['ip'])->not->toBeNull();

    $this->get('/admin/audit-log/'.$this->priceChange->id)
        ->assertOk()
        ->assertSee($this->admin->name)
        ->assertSee('Product OF-001 updated')
        ->assertSeeInOrder(['Srp', '180.00', '195.00'])
        ->assertSeeInOrder(['Reorder Level', '4']);
});

it('describes entries in plain words', function () {
    expect(AuditLog::summary($this->priceChange))->toBe('Product OF-001 updated')
        ->and(AuditLog::changeSummary($this->priceChange))->toContain('Srp: 180.00 → 195.00')
        ->and(AuditLog::changeSummary($this->created))->toEndWith('fields set')
        ->and(AuditLog::summary($this->void))->toBe('Order ORD-000009 voided')
        ->and(AuditLog::changes($this->void))->toBe([['field' => 'Reason', 'old' => null, 'new' => 'Wrong customer']])
        ->and(AuditLog::eventColor('returned'))->toBe('purple');
});

it('filters by event, area, user and record', function () {
    $cashier = cashier();
    $other = activity('Order')->causedBy($cashier)->event('paid')->log('Order ORD-000010 paid, slip OS-000001');

    Livewire::test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$this->priceChange, $this->void, $other])
        ->filterTable('event', 'voided')
        ->assertCanSeeTableRecords([$this->void])
        ->assertCanNotSeeTableRecords([$this->priceChange, $other])
        ->resetTableFilters()
        ->filterTable('log_name', 'Product')
        ->assertCanSeeTableRecords([$this->priceChange, $this->created])
        ->assertCanNotSeeTableRecords([$this->void])
        ->resetTableFilters()
        ->filterTable('causer', $cashier->id)
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$this->void, $this->priceChange])
        ->resetTableFilters()
        ->filterTable('record', ['id' => $this->product->id])
        ->assertCanSeeTableRecords([$this->priceChange]);
});

it('never logs password hashes', function () {
    $user = cashier();
    $user->update(['password' => 'new-secret-123']);

    $json = Activity::where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->get()->toJson();

    expect($json)->not->toContain('"password"')
        ->and($json)->not->toContain('$2y$')
        ->and($json)->not->toContain('pin_hash');
});
