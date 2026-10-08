<?php

namespace App\Services\Backup;

use App\Models\User;
use App\Settings\BackupSettings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Backup now": a mysqldump of the database (spatie/laravel-backup), zipped and kept
 * in storage/app/private/moto-shop. Old zips are pruned by the package's cleanup rules.
 */
class BackupService
{
    public function __construct(private readonly BackupSettings $settings) {}

    /**
     * Runs a database backup and returns the path of the new zip on the backup disk.
     */
    public function run(User $user): string
    {
        $before = $this->backups()->pluck('path')->all();

        $this->exposeSystemEnvironment();

        $exit = Artisan::call('backup:run', ['--only-db' => true, '--disable-notifications' => true]);
        $output = Artisan::output();

        $latest = $this->backups()->first(fn (array $backup) => ! in_array($backup['path'], $before, true));

        if ($exit !== 0 || $latest === null) {
            throw new RuntimeException('Backup failed: '.$this->reason($output));
        }

        Artisan::call('backup:clean', ['--disable-notifications' => true]);

        $this->settings->last_backup_at = now()->toIso8601String();
        $this->settings->save();

        activity('Backup')
            ->causedBy($user)
            ->event('backup')
            ->withProperties(['file' => $latest['name'], 'size' => $latest['size_label']])
            ->log('Database backup created');

        return $latest['path'];
    }

    /**
     * Stored backups, newest first.
     *
     * @return Collection<int, array{path: string, name: string, size: int, size_label: string, modified: int}>
     */
    public function backups(): Collection
    {
        $disk = $this->disk();

        return collect($disk->files($this->folder()))
            ->filter(fn (string $path) => Str::endsWith($path, '.zip'))
            ->map(fn (string $path) => [
                'path' => $path,
                'name' => basename($path),
                'size' => $size = (int) $disk->size($path),
                'size_label' => $this->sizeLabel($size),
                'modified' => (int) $disk->lastModified($path),
            ])
            ->sortByDesc(fn (array $backup) => [$backup['modified'], $backup['name']])
            ->values();
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('backup.backup.destination.disks')[0] ?? 'local');
    }

    public function lastBackupAt(): ?string
    {
        return $this->settings->last_backup_at;
    }

    private function folder(): string
    {
        return (string) config('backup.backup.name');
    }

    /**
     * Under the PHP web server (`php artisan serve`) $_SERVER doesn't carry the OS
     * environment, and Symfony Process only hands a child the variables found there.
     * Without SYSTEMROOT, mysqldump on Windows can't even open a socket (error 10106).
     */
    private function exposeSystemEnvironment(): void
    {
        foreach (['SYSTEMROOT', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'COMSPEC'] as $key) {
            $value = getenv($key);

            if ($value !== false && ! array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }
        }
    }

    private function reason(string $output): string
    {
        $line = collect(preg_split('/\R/', $output) ?: [])
            ->map(fn (string $line) => trim($line))
            ->first(fn (string $line) => Str::contains(Str::lower($line), ['failed', 'error', 'exception']));

        return $line ?: 'mysqldump did not produce a file. Check DB_DUMP_BINARY_PATH in .env.';
    }

    private function sizeLabel(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format(max($bytes, 1) / 1024, 0).' KB';
    }
}
