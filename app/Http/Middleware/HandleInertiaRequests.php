<?php

namespace App\Http\Middleware;

use App\Settings\AppearanceSettings;
use App\Settings\ShopSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props. Only role-appropriate data is shared: the user object is whitelisted
     * (no PIN hash) and cost/profit visibility is a server-side flag.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'shop' => fn () => rescue(function () {
                $shop = app(ShopSettings::class);

                return [
                    'name' => $shop->shop_name,
                    'logo' => $shop->logo ? asset('storage/'.$shop->logo) : null,
                    'posTheme' => app(AppearanceSettings::class)->pos_theme,
                ];
            }, ['name' => config('app.name'), 'logo' => null, 'posTheme' => 'dark'], report: false),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->getLabel(),
                    'must_change_password' => $user->must_change_password,
                ] : null,
                'can' => $user ? [
                    'accessAdmin' => $user->role->canAccessBackOffice(),
                    'viewCost' => Gate::forUser($user)->allows('view-cost'),
                    'usePos' => Gate::forUser($user)->allows('use-pos'),
                    'useServiceBoard' => Gate::forUser($user)->allows('use-service-board'),
                    'takePayment' => Gate::forUser($user)->allows('take-payment'),
                    'needsManagerPin' => ! $user->isAdmin(),
                ] : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
