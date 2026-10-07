# Moto Shop Management System

Parts inventory, POS and service (job order) management for a single-branch motorcycle
parts & maintenance shop. Laravel 12 monolith: Filament 4 back-office at `/admin`,
React + Inertia 2 front of house (POS, Service Board).

> Interim README (build phase 1 of 7). The full guide — pending orders & reservation,
> returns & dispositions, settings, backups — is written in phase 7.

## Setup

Requires PHP 8.3+, Composer, Node 20+, and MySQL with an existing `moto_shop_db`
database (root, empty password by default — see `.env.example`).

```bash
composer run setup   # install, .env, key, db:show, migrate, seed, test DB, build
composer run dev     # PHP server + queue worker + Vite
composer test        # Pest, against moto_shop_db_test only
```

The app runs at **http://localhost:8001** (its own host/port, so it never shares cookies
with another local app on `127.0.0.1:8000`).

Tests never touch `moto_shop_db`: `phpunit.xml` forces `moto_shop_db_test`, which
`php artisan app:setup-testing` creates.

## Default logins (dev seed)

All passwords are `password`. Manager PIN for the admin accounts: `1234`.

| Email | Role | Lands on |
|---|---|---|
| superadmin@example.com | Super Admin | /admin |
| admin@example.com | Admin / Manager | /admin |
| cashier@example.com | Cashier | /pos |
| mechanic@example.com | Mechanic | /service-board |
| clerk@example.com | Inventory Clerk | /admin |

## Version pins

The official React starter kit's `main` branch now targets Laravel 13 / Inertia 3. This
project is based on starter-kit commit `8f79a71dd98d81eb5c2f409f3426331cc0d30ded`
(last Laravel 12 / Inertia 2 version) and pins `laravel/framework:^12.69` and
`filament/filament:^4.15` so `composer update` cannot jump a major version.
