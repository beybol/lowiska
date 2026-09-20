<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Http\Middleware\TwoFactorMiddleware;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->registration(Register::class)
            ->emailVerification()
            // ⚠️ Pasek boczny panelu administratora da się zwinąć, bo sub-nawigacja
            // rekordu (`FisheryResource::getSubNavigationPosition()`) jest po lewej —
            // bez tego przy edycji łowiska dwa paski zjadały szerokość formularza.
            // Panel właściciela tego nie potrzebuje: ma `topNavigation()`, więc paska
            // bocznego w ogóle nie renderuje.
            // ⚠️ Kolejność GRUP ustawia się tutaj, a nie sortowaniem na zasobach —
            // `getNavigationSort()` porządkuje wyłącznie pozycje WEWNĄTRZ grupy.
            // Bez tego wpisu grupy idą alfabetycznie („Dostępy" przed „Słownikami").
            ->navigationGroups([
                __('Dictionaries'),
                __('Access'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                TwoFactorMiddleware::class,
            ])
            ->plugins([
                // Role trafiają do grupy „Dostępy" razem z użytkownikami. Zasób jest
                // dostarczany przez wtyczkę, więc nawigację ustawia się na wtyczce,
                // a nie nadpisaniem metod na klasie zasobu.
                FilamentShieldPlugin::make()
                    ->navigationGroup(__('Access'))
                    ->navigationLabel(__('Roles'))
                    ->navigationSort(2),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
