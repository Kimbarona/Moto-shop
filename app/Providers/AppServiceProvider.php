<?php

namespace App\Providers;

use App\Enums\Role;
use App\Http\Responses\LoginResponse;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerMotorcycle;
use App\Models\ExpenseCategory;
use App\Models\Mechanic;
use App\Models\MotorcycleMake;
use App\Models\MotorcycleModel;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\AdminMasterDataPolicy;
use App\Policies\MasterDataPolicy;
use App\Settings\SecuritySettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSecuritySettings();
        $this->configureAuthorization();
        $this->configureAuditLog();

        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User
            ? $event->user->forceFill(['last_login_at' => now()])->saveQuietly()
            : null);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());
    }

    /**
     * Session timeout and password length come from App Settings → Security.
     */
    protected function configureSecuritySettings(): void
    {
        // Settings load lazily, so read the values inside rescue(): before the first
        // migration (fresh install, test bootstrap) the settings table does not exist yet.
        $security = rescue(function () {
            $settings = app(SecuritySettings::class);

            return [$settings->session_timeout_minutes, $settings->password_min_length];
        }, null, report: false);

        if ($security) {
            config(['session.lifetime' => max(5, $security[0])]);
        }

        $minLength = max(6, $security[1] ?? 8);

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min($minLength)->letters()->numbers()
            : Password::min($minLength));
    }

    /**
     * The access matrix (spec §2). Model policies cover CRUD; these gates cover capabilities.
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);

        foreach ([Category::class, Brand::class, Unit::class, MotorcycleMake::class, MotorcycleModel::class,
            Product::class, Customer::class, CustomerMotorcycle::class, Supplier::class] as $model) {
            Gate::policy($model, MasterDataPolicy::class);
        }

        foreach ([Service::class, ServicePackage::class, Mechanic::class, PaymentMethod::class, ExpenseCategory::class] as $model) {
            Gate::policy($model, AdminMasterDataPolicy::class);
        }

        $roles = fn (Role ...$roles) => fn (User $user) => $user->is_active && $user->hasRole(...$roles);

        // Never send cost, profit or inventory value to cashiers or mechanics.
        Gate::define('view-cost', $roles(Role::SuperAdmin, Role::Admin));
        Gate::define('edit-pricing', $roles(Role::SuperAdmin, Role::Admin));

        Gate::define('use-pos', $roles(Role::SuperAdmin, Role::Admin, Role::Cashier));
        Gate::define('use-service-board', $roles(Role::SuperAdmin, Role::Admin, Role::Cashier, Role::Mechanic));
        Gate::define('take-payment', $roles(Role::SuperAdmin, Role::Admin, Role::Cashier));
        Gate::define('view-stock', $roles(...Role::cases()));
        Gate::define('manage-inventory', $roles(Role::SuperAdmin, Role::Admin, Role::InventoryClerk));

        Gate::define('view-reports', $roles(Role::SuperAdmin, Role::Admin));
        Gate::define('view-inventory-reports', $roles(Role::SuperAdmin, Role::Admin, Role::InventoryClerk));
        Gate::define('view-audit-log', $roles(Role::SuperAdmin, Role::Admin));
        Gate::define('view-settings', $roles(Role::SuperAdmin, Role::Admin));
        Gate::define('manage-settings', $roles(Role::SuperAdmin));
    }

    /**
     * Every audit entry records the client IP.
     */
    protected function configureAuditLog(): void
    {
        Activity::saving(function (Activity $activity) {
            if (! app()->runningInConsole() || app()->runningUnitTests()) {
                $activity->properties = $activity->properties->put('ip', request()->ip());
            }
        });
    }
}
