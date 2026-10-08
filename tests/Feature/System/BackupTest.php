<?php

use App\Filament\Pages\AppSettings;
use App\Services\Backup\BackupService;
use App\Settings\BackupSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
});

it('dumps the database to a zip, downloads it and records the date', function () {
    $this->actingAs(superAdmin());

    Livewire::test(AppSettings::class)
        ->call('backupNow')
        ->assertNotified('Backup saved');

    $backups = app(BackupService::class)->backups();

    expect($backups)->toHaveCount(1)
        ->and($backups->first()['path'])->toStartWith('moto-shop/')
        ->and($backups->first()['size'])->toBeGreaterThan(1000)
        ->and(app(BackupSettings::class)->refresh()->last_backup_at)->not->toBeNull();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($backups->first()['path']));
    $dump = $zip->getFromIndex(0);
    $zip->close();

    expect($dump)->toContain('CREATE TABLE `orders`')
        ->and($dump)->toContain('CREATE TABLE `sequences`');

    $log = Activity::where('log_name', 'Backup')->latest('id')->first();

    expect($log->event)->toBe('backup')
        ->and($log->properties['file'])->toBe($backups->first()['name']);

    $this->get('/admin/settings?tab=backup::data::tab')->assertOk()->assertSee($backups->first()['name']);
});

it('reports a failed backup without changing the last backup date', function () {
    config(['database.connections.mysql.dump.dump_binary_path' => 'C:/no/such/mysql/bin']);
    $this->actingAs(superAdmin());

    Livewire::test(AppSettings::class)
        ->call('backupNow')
        ->assertNotified('Backup failed')
        ->assertNoFileDownloaded();

    expect(app(BackupService::class)->backups())->toBeEmpty()
        ->and(app(BackupSettings::class)->refresh()->last_backup_at)->toBeNull();
});

it('lets only a super admin run a backup', function () {
    $this->actingAs(admin());

    Livewire::test(AppSettings::class)->call('backupNow')->assertForbidden();
    Livewire::test(AppSettings::class)->call('downloadLatestBackup')->assertForbidden();

    $this->get('/admin/settings')->assertOk()->assertSee('Last backup')->assertDontSee('Backup now');
    $this->actingAs(superAdmin())->get('/admin/settings')->assertSee('Backup now');

    expect(app(BackupService::class)->backups())->toBeEmpty();
});
