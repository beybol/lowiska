<?php

use App\Http\Controllers\MailPreviewController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\ReservationLinkController;
use App\Http\Controllers\Portal\SeoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Middleware\SetPortalLocale;
use App\Mail\MailPreviews;
use App\Services\PortalRoutes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal wędkarza — zadanie 031, schemat adresów: ADR-021 (opcja B)
|--------------------------------------------------------------------------
|
| ⚠️ Kolejność ma znaczenie: strony informacyjne PRZED trasą łowiska — `/pl/dokumenty-prawne/regulamin`
| ma ten sam kształt co `/{język}/{województwo}/{slug}`.
|
| ⚠️ Krótki adres łowiska (`/{slug}`) obsługuje `Route::fallback()` na końcu pliku, NIGDY trasa
| `/{slug}`: tylko fallback jest zawsze ostatni względem tras pakietów (Filament, Livewire).
|
| ⚠️ Nowa trasa pierwszego poziomu → wpis w `PortalSlugs::RESERVED` (`strona-publiczna.md`).
*/

Route::get('/', [PortalController::class, 'root'])->name('portal.root');
Route::get('robots.txt', [SeoController::class, 'robots'])->name('portal.robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('portal.sitemap');

Route::middleware(SetPortalLocale::class)->group(function () {
    foreach (PortalRoutes::PAGES as $page => $slugs) {
        foreach ($slugs as $locale => $slug) {
            Route::get("{$locale}/{$slug}", [PortalController::class, 'page'])
                ->name(PortalRoutes::pageRouteName($page, $locale))
                ->defaults('page', $page)
                ->defaults('locale', $locale);
        }
    }

    // Strony pod tokenem z maila o rezerwacji (zadanie 043) — z limitem prób na adres IP.
    foreach (PortalRoutes::RESERVATION_PAGES as $page => $slugs) {
        foreach ($slugs as $locale => $slug) {
            Route::get("{$locale}/{$slug}/{token}", [ReservationLinkController::class, $page])
                ->where('token', '[A-Za-z0-9]{20,100}')
                ->middleware('throttle:'.ReservationLinkController::ATTEMPTS_PER_MINUTE.',1')
                ->name(PortalRoutes::reservationRouteName($page, $locale))
                ->defaults('locale', $locale);
            Route::post("{$locale}/{$slug}/{token}", [ReservationLinkController::class, $page.'Submit'])
                ->where('token', '[A-Za-z0-9]{20,100}')
                ->middleware('throttle:'.ReservationLinkController::ATTEMPTS_PER_MINUTE.',1')
                ->name(PortalRoutes::reservationRouteName($page, $locale).'.submit')
                ->defaults('locale', $locale);
        }
    }

    // Strona łączenia konta z łowiskiem (zadanie 049) — bez tokenu w adresie, relacja ze znacznika w sesji.
    // `two_factor`: sesja z nieprzepisanym jeszcze kodem 2FA nie może połączyć konta.
    foreach (PortalRoutes::RESERVATION_ACCOUNT_PAGE as $locale => $slug) {
        Route::get("{$locale}/{$slug}", [ReservationLinkController::class, 'account'])
            ->middleware(['two_factor', 'throttle:'.ReservationLinkController::ATTEMPTS_PER_MINUTE.',1'])
            ->name(PortalRoutes::reservationAccountRouteName($locale))
            ->defaults('locale', $locale);
        Route::post("{$locale}/{$slug}", [ReservationLinkController::class, 'accountSubmit'])
            ->middleware(['two_factor', 'throttle:'.ReservationLinkController::ATTEMPTS_PER_MINUTE.',1'])
            ->name(PortalRoutes::reservationAccountRouteName($locale).'.submit')
            ->defaults('locale', $locale);
    }

    Route::prefix('{locale}')
        ->whereIn('locale', PortalRoutes::LOCALES)
        ->name('portal.')
        ->group(function () {
            Route::get('/', [PortalController::class, 'home'])->name('home');
            Route::get('{state}', [PortalController::class, 'region'])
                ->where('state', '[a-z0-9-]+')
                ->name('region');
            Route::get('{state}/{fishery}', [PortalController::class, 'fishery'])
                ->where(['state' => '[a-z0-9-]+', 'fishery' => '[a-z0-9-]+'])
                ->name('fishery');
        });
});

/*
|--------------------------------------------------------------------------
| Breeze — profil i logowanie społecznościowe
|--------------------------------------------------------------------------
|
| Pulpitu `/dashboard` nie ma (zadanie 031): po logowaniu użytkownik trafia do panelu
| (`PanelHome::urlFor()`).
*/

Route::middleware(['auth', 'two_factor'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Zgody przed założeniem konta przez dostawcę logowania (zadanie 049) — PRZED `auth/{provider}`,
// które inaczej przechwyciłoby ten adres jako nazwę dostawcy.
Route::middleware('guest')->group(function () {
    Route::get('auth/consents', [SocialAuthController::class, 'consents'])->name('social.consents');
    Route::post('auth/consents', [SocialAuthController::class, 'consentsSubmit'])->name('social.consents.submit');
});

Route::get('auth/{provider}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('auth/{provider}/callback', [
    SocialAuthController::class,
    'callback',
])->name('social.callback');

require __DIR__.'/auth.php';

/*
| Podgląd wiadomości bez wysyłki (zadanie 040) — WYŁĄCZNIE `local` i `testing` (`MailPreviews`).
| Segment `dev` jest na liście `PortalSlugs::RESERVED`, bo trasy nie ma na produkcji.
*/
if (MailPreviews::allowed()) {
    Route::get('dev/mail-preview', [MailPreviewController::class, 'index'])->name('dev.mail-preview.index');
    Route::get('dev/mail-preview/{mail}/{format?}', [MailPreviewController::class, 'show'])
        ->whereIn('format', ['html', 'text'])
        ->name('dev.mail-preview');
}

Route::fallback([PortalController::class, 'fallback'])->name('portal.fallback');
