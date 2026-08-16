<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Filament\Resources\AdditionalServiceResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\LongTermPermitResource;
use App\Filament\Resources\PositionResource;
use App\Http\Middleware\TwoFactorMiddleware;
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

class OwnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('owner')
            ->path('owner')
            ->login(Login::class)
            ->registration(Register::class)
            ->emailVerification()
            ->brandName(__('Fisherya owner panel'))
            ->topNavigation()
            ->colors([
                'primary' => Color::Green,
            ])
            ->resources([
                CompanyResource::class,
                FisheryResource::class,
                LongTermPermitResource::class,
                AdditionalServiceResource::class,
                PositionResource::class,
            ])
            ->discoverResources(in: app_path('Filament/Owner/Resources'), for: 'App\\Filament\\Owner\\Resources')
            ->discoverPages(in: app_path('Filament/Owner/Pages'), for: 'App\\Filament\\Owner\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Owner/Widgets'), for: 'App\\Filament\\Owner\\Widgets')
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
                // ⚠️ Drugi składnik obowiązuje TAK SAMO jak w panelu administratora.
                // Bez tego `Login::authenticate()` wołało `parent::authenticate()`
                // PRZED rzuceniem wyjątku, więc sesja guarda `web` już istniała —
                // wystarczyło zignorować przekierowanie na `/verify` i wejść wprost
                // na `/owner/fisheries`. `TODO.md` odraczało to do upgrade'u na
                // Laravel 13 + najnowszego Filamenta; upgrade wszedł zadaniem 009,
                // więc warunek odroczenia wygasł (audyt bezpieczeństwa, zadanie 012).
                TwoFactorMiddleware::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
