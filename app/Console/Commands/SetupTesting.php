<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use Throwable;

/**
 * Creates the separate test database (moto_shop_db_test) and .env.testing so that
 * Pest NEVER touches the real moto_shop_db.
 */
class SetupTesting extends Command
{
    protected $signature = 'app:setup-testing';

    protected $description = 'Create the test database and .env.testing (tests never use the real database)';

    public function handle(): int
    {
        $connection = config('database.connections.mysql');
        $testDatabase = $connection['database'].'_test';

        if (! str_ends_with($testDatabase, '_test')) {
            $this->error('Refusing to continue: test database name must end with "_test".');

            return self::FAILURE;
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s', $connection['host'], $connection['port']),
                $connection['username'],
                $connection['password'],
            );

            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                str_replace('`', '', $testDatabase),
            ));
        } catch (Throwable $e) {
            $this->error('Could not create the test database: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Test database [{$testDatabase}] is ready.");

        $envTesting = base_path('.env.testing');

        if (! file_exists($envTesting)) {
            $env = (string) file_get_contents(base_path('.env'));
            $env = preg_replace('/^APP_ENV=.*$/m', 'APP_ENV=testing', $env);
            $env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE='.$testDatabase, $env);
            file_put_contents($envTesting, $env);

            $this->components->info('.env.testing created.');
        }

        return self::SUCCESS;
    }
}
