<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ManagerPin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForcePasswordChange;
use App\Settings\ShopSettings;
use Filament\Actions\Action;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Back-office for admins, super admins and inventory clerks. Login happens on the shared
 * /login page (no separate Filament login), so both sides feel like one product.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(fn () => rescue(fn () => app(ShopSettings::class)->shop_name, config('app.name'), report: false))
            ->brandLogo(fn () => rescue(fn () => app(ShopSettings::class)->logo ? asset('storage/'.app(ShopSettings::class)->logo) : null, null, report: false))
            ->brandLogoHeight('2.25rem')
            ->font('Barlow', provider: LocalFontProvider::class)
            ->colors([
                'primary' => Color::hex('#FF6B1A'), // Racing Orange
                'warning' => Color::hex('#FFC93C'), // Hi-Vis Yellow
                'success' => Color::hex('#22C55E'),
                'danger' => Color::Red,
                'info' => Color::Sky,
                'purple' => Color::Purple, // refunds
                'gray' => Color::Slate,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->darkMode()
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->navigationGroups([
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Inventory'),
                NavigationGroup::make('Masterlist'),
                NavigationGroup::make('Expenses'),
                NavigationGroup::make('Reports'),
                NavigationGroup::make('System'),
            ])
            ->userMenuItems([
                Action::make('front-of-house')
                    ->label('Open POS / Service Board')
                    ->url('/')
                    ->icon(Heroicon::OutlinedComputerDesktop)
                    ->visible(fn () => auth()->user()?->can('use-service-board')),
                Action::make('manager-pin')
                    ->label('My manager PIN')
                    ->url(fn () => ManagerPin::getUrl())
                    ->icon(Heroicon::OutlinedKey)
                    ->visible(fn () => auth()->user()?->isAdmin()),
                Action::make('password')
                    ->label('Change password')
                    ->url('/settings/security')
                    ->icon(Heroicon::OutlinedLockClosed),
            ])
            ->databaseNotifications(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsActive::class,
                ForcePasswordChange::class,
            ]);
    }
}
