<?php

namespace App\Providers;

use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Environments allowed to run on the local filesystem disk (zadanie 005).
     * `testing` must stay whitelisted: phpunit.xml forces APP_ENV=testing without
     * overriding FILESYSTEM_DISK, so without this exemption the guard would break
     * the entire test suite at boot.
     */
    private const ENVIRONMENTS_ALLOWING_LOCAL_DISK = ['local', 'testing'];

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
        static::assertUploadDiskIsSafe(
            app()->environment(),
            config('filesystems.disks.'.config('filesystems.default').'.driver'),
        );

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

    /**
     * Refuse to start outside local/testing if uploads would silently land on the
     * container's ephemeral local disk — Cloud Run wipes it on every deploy, scale-to-zero
     * cold start, and crash restart (zadanie 005). Takes the RESOLVED disk driver, not the
     * raw env var or disk name, matching the pattern already used by the database gate in
     * tests/TestCase.php (ADR-001). Pure function — no config()/app() lookups inside — so it
     * is testable from tests/Unit without booting the framework.
     */
    public static function assertUploadDiskIsSafe(string $environment, ?string $resolvedDriver): void
    {
        if (in_array($environment, self::ENVIRONMENTS_ALLOWING_LOCAL_DISK, strict: true)) {
            return;
        }

        if ($resolvedDriver !== 'local') {
            return;
        }

        throw new RuntimeException(
            "Dysk uploadów rozwiązuje się do sterownika 'local' poza środowiskiem lokalnym/testowym "
            .'— pliki znikną przy najbliższym restarcie kontenera. '
            .'Ustaw FILESYSTEM_DISK=gcs, FILAMENT_FILESYSTEM_DISK=gcs i GOOGLE_CLOUD_STORAGE_BUCKET.'
        );
    }
}
