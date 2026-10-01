<?php

namespace App\Providers;

use App\Support\FisheryMediaActivity;
use App\Support\SanitizingMediaFilesystem;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Filesystem as MediaFilesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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
        // Każde zdjęcie dodane do medialibrary jest oczyszczane, zanim trafi do bucketu (ADR-023).
        $this->app->bind(MediaFilesystem::class, SanitizingMediaFilesystem::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Zdjęcia łowiska nie są już polami `Fishery`, więc dziennik zmian zapisuje je jawnie (`dziennik-zmian.md` §5).
        Event::listen(MediaHasBeenAddedEvent::class, fn (MediaHasBeenAddedEvent $event) => FisheryMediaActivity::added($event->media));
        Media::deleted(fn (Media $media) => FisheryMediaActivity::removed($media));

        // Każdy dysk, na który trafiają pliki: domyślny, uploady Filamenta, oryginały i warianty zdjęć oraz pliki
        // tymczasowe Livewire. Brak zmiennej w jednym z nich spadałby po cichu na dysk instancji (038).
        foreach ([
            config('filesystems.default'),
            config('filament.default_filesystem_disk'),
            config('media-library.disk_name'),
            config('media-library.conversions_disk_name'),
            config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'),
        ] as $disk) {
            static::assertUploadDiskIsSafe(app()->environment(), config('filesystems.disks.'.$disk.'.driver'));
        }

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
            .'Ustaw FILESYSTEM_DISK=gcs-private, FILAMENT_FILESYSTEM_DISK=gcs, MEDIA_DISK=gcs-private, '
            .'LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=gcs-private, GOOGLE_CLOUD_STORAGE_BUCKET '
            .'i GOOGLE_CLOUD_STORAGE_PRIVATE_BUCKET.'
        );
    }
}
