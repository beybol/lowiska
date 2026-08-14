<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        VerifyEmail::toMailUsing(function ($notifiable, $url) {
            return (new MailMessage)
                ->subject(__('Verify e-mail address'))
                ->view('emails.verify-email', ['url' => $url]);
        });
        FilamentAsset::register([
            Css::make('custom-stylesheet', asset('css/filament-extend.css')),
        ]);
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch->locales(['pl', 'en']);
        });
    }
}
