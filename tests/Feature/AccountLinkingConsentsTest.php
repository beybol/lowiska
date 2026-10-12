<?php

namespace Tests\Feature;

use App\Enums\AnglerLinkStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentContext;
use App\Enums\ConsentDecision;
use App\Enums\ConsentKind;
use App\Enums\ConsentSender;
use App\Enums\DocumentType;
use App\Filament\Resources\PlatformDocumentResource\Pages\CreatePlatformDocument;
use App\Filament\Resources\PlatformDocumentResource\Pages\ListPlatformDocuments;
use App\Mail\ReservationRequestMail;
use App\Models\ConsentEvent;
use App\Models\Document;
use App\Models\DocumentTranslation;
use App\Models\Fishery;
use App\Models\PlatformDocument;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\SendTwoFactorCode;
use App\Services\AnglerLinkSession;
use App\Services\ConsentRegistry;
use App\Services\NotificationPreferences;
use App\Services\PanelHome;
use App\Services\PortalRoutes;
use App\Services\ReservationConfirmation;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use LogicException;
use Tests\Support\ReservationFixtures;

/**
 * Potwierdzenie rezerwacji bez konta, połączenie z kontem po zalogowaniu albo rejestracji, zgody przy każdym
 * zakładaniu konta i dokumenty Fisherya (zadanie 049, ADR-024 aktualizacja 2026-10-11, ADR-028).
 */
beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-04-01 12:00', 'Europe/Warsaw'));
    Mail::fake();
    Notification::fake();
});

afterEach(function () {
    Date::setTestNow();
});

/**
 * Rezerwacja z prośbą o potwierdzenie — zwraca rezerwację, łowisko i adres strony pod tokenem.
 *
 * @return array{0: Reservation, 1: Fishery, 2: string}
 */
function linkRequest049(): array
{
    [$fishery, $position] = ReservationFixtures::bookableFishery();
    $reservation = ReservationFixtures::book($position, '2026-05-13', 2, ['confirmBy' => CarbonImmutable::now()->addDays(2)]);
    ReservationConfirmation::afterBooking($reservation);

    $url = null;
    Mail::assertSent(ReservationRequestMail::class, function (ReservationRequestMail $mail) use (&$url): bool {
        $url = $mail->confirmUrl;

        return true;
    });

    return [$reservation->fresh(), $fishery, $url];
}

function platformDocument049(DocumentType $type = DocumentType::Terms, bool $required = true): PlatformDocument
{
    return PlatformDocument::query()->create([
        'type' => $type,
        'title' => $type === DocumentType::Terms ? 'Regulamin Fisherya' : 'Polityka prywatności Fisherya',
        'effective_from' => '2026-01-01',
        'content' => '<p>Treść dokumentu Fisherya</p>',
        'required_at_registration' => $required,
    ]);
}

function fisheryDocument049(Fishery $fishery): Document
{
    return Document::factory()->create([
        'fishery_id' => $fishery->id,
        'type' => DocumentType::Terms,
        'title' => 'Regulamin łowiska',
        'effective_from' => '2026-01-01',
        'required_at_registration' => true,
    ]);
}

function fakeGoogle049(string $email): void
{
    $user = (new SocialiteUser)
        ->setRaw(['sub' => 'g-049', 'id' => 'g-049', 'email' => $email, 'email_verified' => true, 'given_name' => 'Jan', 'family_name' => 'Kowalski'])
        ->map(['id' => 'g-049', 'email' => $email, 'name' => 'Jan Kowalski']);

    Socialite::fake('google', $user);
}

// ─── Strona łączenia konta ───────────────────────────────────────────────────────

test('the account page needs the session marker — without it the link is invalid', function () {
    $this->get(PortalRoutes::reservationAccountUrl('pl'))->assertNotFound();
});

test('signed in with another account: refusal, nothing linked, and signing out keeps the marker', function () {
    $other = User::factory()->create(['email' => 'ktos-inny@example.com']);
    [$reservation, , $url] = linkRequest049();
    $this->post($url, ['action' => 'account']);

    $this->actingAs($other)->get(PortalRoutes::reservationAccountUrl('pl'))->assertOk()
        ->assertSee(__('You are signed in with a different account.'));
    $this->actingAs($other)->post(PortalRoutes::reservationAccountUrl('pl'), ['action' => 'link'])
        ->assertRedirect(PortalRoutes::reservationAccountUrl('pl'));

    expect($reservation->angler->fresh()->user_id)->toBeNull();

    $this->actingAs($other)->post(PortalRoutes::reservationAccountUrl('pl'), ['action' => 'logout'])
        ->assertRedirect(PortalRoutes::reservationAccountUrl('pl'));

    $this->assertGuest();
    expect(AnglerLinkSession::hash())->toBe(hash('sha256', basename($url)));
});

test('linking requires the fishery documents marked for linking and records their acceptance', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    [$reservation, $fishery, $url] = linkRequest049();
    $terms = fisheryDocument049($fishery);
    $this->post($url, ['action' => 'account']);

    $this->actingAs($user)->get(PortalRoutes::reservationAccountUrl('pl'))->assertSee('Regulamin łowiska');
    $this->actingAs($user)->post(PortalRoutes::reservationAccountUrl('pl'), ['action' => 'link'])
        ->assertSessionHasErrors('consents.'.$terms->id);

    expect($reservation->angler->fresh()->status)->toBe(AnglerLinkStatus::Pending);

    $this->actingAs($user)->post(PortalRoutes::reservationAccountUrl('pl'), ['action' => 'link', 'consents' => [$terms->id => '1']])
        ->assertOk();

    $angler = $reservation->angler->fresh();
    $event = ConsentEvent::query()->sole();

    expect($angler->status)->toBe(AnglerLinkStatus::Linked)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->document_id)->toBe($terms->id)
        ->and($event->kind)->toBe(ConsentKind::Acceptance)
        ->and($event->decision)->toBe(ConsentDecision::Granted)
        ->and($event->context)->toBe(ConsentContext::AnglerLink)
        ->and($event->fishery_id)->toBe($fishery->id)
        ->and($event->fishery_angler_id)->toBe($angler->id)
        ->and($event->locale)->toBe('pl');
});

test('creating an account from the link uses the address from the request, verifies it and needs both sets of consents', function () {
    [$reservation, $fishery, $url] = linkRequest049();
    $platformTerms = platformDocument049();
    $privacy = platformDocument049(DocumentType::PrivacyPolicy);
    $fisheryTerms = fisheryDocument049($fishery);
    $this->post($url, ['action' => 'account']);

    $this->get(PortalRoutes::reservationAccountUrl('pl'))->assertOk()
        ->assertSee(__('Create an account and link'))
        ->assertSee('Regulamin Fisherya')
        ->assertSee('Regulamin łowiska');

    $form = [
        'action' => 'register',
        'name' => 'Jan',
        'surname' => 'Kowalski',
        // ⚠️ Adres z żądania jest ignorowany — konto dostaje adres z prośby.
        'email' => 'napastnik@example.com',
        'password' => 'Haslo-Testowe-123', // gitleaks:allow
        'password_confirmation' => 'Haslo-Testowe-123', // gitleaks:allow
    ];

    $this->post(PortalRoutes::reservationAccountUrl('pl'), $form)->assertSessionHasErrors([
        'consents.'.$platformTerms->id, 'consents.'.$privacy->id, 'consents.'.$fisheryTerms->id,
    ]);
    expect(User::query()->where('email', 'jan@example.com')->exists())->toBeFalse();

    $this->post(PortalRoutes::reservationAccountUrl('pl'), $form + ['consents' => [
        $platformTerms->id => '1', $privacy->id => '1', $fisheryTerms->id => '1',
    ]])->assertOk()->assertSee(__('Your account is linked'));

    $user = User::query()->where('email', 'jan@example.com')->sole();

    expect($user->email)->toBe('jan@example.com')
        ->and($user->has_password)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('owner'))->toBeFalse()
        ->and($reservation->angler->fresh()->user_id)->toBe($user->id)
        ->and(User::query()->where('email', 'napastnik@example.com')->exists())->toBeFalse();

    $this->assertAuthenticatedAs($user);

    $events = ConsentEvent::query()->orderBy('id')->get();

    expect($events->pluck('context')->map->value->all())->toBe(['registration', 'registration', 'angler_link'])
        ->and($events->firstWhere('document_id', $privacy->id)->kind)->toBe(ConsentKind::Acknowledgement)
        ->and($events->firstWhere('document_id', $platformTerms->id)->kind)->toBe(ConsentKind::Acceptance);
});

// ─── Powrót po logowaniu (R2) ─────────────────────────────────────────────────────

test('the two-factor step returns to the account page while linking and to the panel otherwise', function () {
    $user = User::factory()->create(['email' => 'jan@example.com']);
    [, , $url] = linkRequest049();
    $this->post($url, ['action' => 'account']);
    $user->generateTwoFactorCode();

    // Kod niewpisany — strona łączenia odsyła do 2FA, nie łączy.
    $this->actingAs($user)->get(PortalRoutes::reservationAccountUrl('pl'))->assertRedirect(route('verify.index'));

    $this->actingAs($user)
        ->post(route('verify.store'), ['two_factor_code' => $user->fresh()->two_factor_code])
        ->assertRedirect(PortalRoutes::reservationAccountUrl('pl'));

    AnglerLinkSession::forget();
    $user->generateTwoFactorCode();

    $this->actingAs($user)
        ->post(route('verify.store'), ['two_factor_code' => $user->fresh()->two_factor_code])
        ->assertRedirect(PanelHome::urlFor($user));
});

// ─── Zgody przy zakładaniu konta (R3) ─────────────────────────────────────────────

test('registration requires the Fisherya documents in force and records them', function () {
    $terms = platformDocument049();
    $form = [
        'name' => 'Ola', 'surname' => 'Nowak', 'email' => 'ola@example.com',
        'password' => 'Haslo-Testowe-123', 'password_confirmation' => 'Haslo-Testowe-123', // gitleaks:allow
    ];

    $this->get('/register')->assertOk()->assertSee('Regulamin Fisherya');
    $this->post('/register', $form)->assertSessionHasErrors('consents.'.$terms->id);
    expect(User::query()->where('email', 'ola@example.com')->exists())->toBeFalse();

    $this->post('/register', $form + ['consents' => [$terms->id => '1']])->assertRedirect();

    $user = User::query()->where('email', 'ola@example.com')->sole();

    expect((new ConsentRegistry)->hasAccepted($user, $terms))->toBeTrue()
        ->and(ConsentEvent::query()->sole()->context)->toBe(ConsentContext::Registration);
});

test('without a Fisherya document in force there is nothing to accept', function () {
    platformDocument049(required: false);

    expect((new ConsentRegistry)->requiredPlatformDocuments())->toBe([]);

    $this->post('/register', [
        'name' => 'Ola', 'surname' => 'Nowak', 'email' => 'ola@example.com',
        'password' => 'Haslo-Testowe-123', 'password_confirmation' => 'Haslo-Testowe-123', // gitleaks:allow
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'ola@example.com')->exists())->toBeTrue();
});

test('the panel registration form asks for the Fisherya documents', function () {
    $terms = platformDocument049();

    $this->assertStringContainsString('data.consents.'.$terms->id, $this->get('/admin/register')->getContent());
});

test('a new Google account is not created before the consents, also from the sign-in page', function () {
    $terms = platformDocument049();
    fakeGoogle049('nowy@example.com');

    $this->get(route('social.callback', ['provider' => 'google']))->assertRedirect(route('social.consents'));
    expect(User::query()->where('email', 'nowy@example.com')->exists())->toBeFalse();

    $this->get(route('social.consents'))->assertOk()->assertSee('Regulamin Fisherya');
    $this->post(route('social.consents.submit'))->assertSessionHasErrors('consents.'.$terms->id);
    expect(User::query()->where('email', 'nowy@example.com')->exists())->toBeFalse();

    $this->post(route('social.consents.submit'), ['consents' => [$terms->id => '1']])->assertRedirect(route('verify.index'));

    $user = User::query()->where('email', 'nowy@example.com')->sole();

    expect($user->has_password)->toBeFalse()
        ->and($user->provider)->toBe('google')
        ->and((new ConsentRegistry)->hasAccepted($user, $terms))->toBeTrue();

    Notification::assertSentTo($user, SendTwoFactorCode::class);
});

test('Google with an address different from the request does not link the relation', function () {
    [$reservation, , $url] = linkRequest049();
    $this->post($url, ['action' => 'account']);
    fakeGoogle049('inny@example.com');

    $this->get(route('social.callback', ['provider' => 'google']))->assertRedirect(route('verify.index'));
    $user = User::query()->where('email', 'inny@example.com')->sole();
    $user->forceFill(['two_factor_code' => null, 'two_factor_expires_at' => null])->save();

    $this->actingAs($user)->post(PortalRoutes::reservationAccountUrl('pl'), ['action' => 'link'])
        ->assertRedirect(PortalRoutes::reservationAccountUrl('pl'));

    expect($reservation->angler->fresh()->user_id)->toBeNull();
});

test('no code path creates an account with an unknown password except the provider sign-in', function () {
    $offenders = [];

    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php'
            && preg_match("/'has_password'\s*=>\s*false/", (string) file_get_contents($file->getPathname()))) {
            $offenders[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    sort($offenders);

    // `HasPasswordBackfill` nie zakłada kont — ustawia flagę istniejącym kontom w migracji z zadania 028.
    expect($offenders)->toBe([
        'Http'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'SocialAuthController.php',
        'Services'.DIRECTORY_SEPARATOR.'HasPasswordBackfill.php',
    ]);
});

// ─── Dokumenty Fisherya i rejestr ─────────────────────────────────────────────────

test('the administrator publishes a Fisherya document version with a translation; an owner has no access', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->createSuperAdmin());

    Livewire::test(CreatePlatformDocument::class)
        ->fillForm([
            'type' => DocumentType::Terms->value,
            'locale' => 'pl',
            'title' => 'Regulamin portalu',
            'effective_from' => '2026-04-20',
            'change_kind' => 'reacceptance',
            'announced_on' => '2026-04-05',
            'content' => '<p>Treść</p>',
            'required_at_registration' => true,
            'translations' => [['locale' => 'en', 'title' => 'Portal terms', 'content' => '<p>Content</p>']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = PlatformDocument::query()->sole();

    expect($document->fishery_id)->toBeNull()
        ->and($document->change_kind->value)->toBe('reacceptance')
        ->and($document->variantFor('en')['title'])->toBe('Portal terms')
        ->and($document->variantFor('de')['locale'])->toBe('pl');

    Livewire::test(ListPlatformDocuments::class)->assertCanSeeTableRecords([$document]);

    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/admin/platform-documents')->assertForbidden();
});

test('a Fisherya document in force can not be changed and the terms page shows it in the visitor language', function () {
    $terms = platformDocument049();
    // Wersja jest już w mocy, więc wariant dopisujemy z pominięciem blokady modelu (stan „sprzed wejścia w życie").
    DocumentTranslation::withoutEvents(fn () => $terms->translations()->create(['locale' => 'en', 'title' => 'Fisherya terms', 'content' => '<p>English content</p>']));

    expect(fn () => $terms->update(['content' => '<p>Zmiana</p>']))->toThrow(ValidationException::class)
        ->and(fn () => $terms->translations()->first()->update(['content' => '<p>Zmiana</p>']))->toThrow(ValidationException::class);

    $this->get(PortalRoutes::pageUrl('terms', 'pl'))->assertOk()->assertSee('Treść dokumentu Fisherya');
    $this->get(PortalRoutes::pageUrl('terms', 'en'))->assertOk()->assertSee('English content');

    // Dokument łowiska nie jest dokumentem Fisherya.
    [$reservation, $fishery] = linkRequest049();
    fisheryDocument049($fishery);

    expect(PlatformDocument::query()->count())->toBe(1);
});

test('consent events are append-only and marketing consent is per sender, channel and fishery', function () {
    [, $fishery] = linkRequest049();
    $user = User::factory()->create();
    $registry = new ConsentRegistry;

    $event = ConsentEvent::query()->create([
        'user_id' => $user->id, 'kind' => ConsentKind::Marketing, 'decision' => ConsentDecision::Granted,
        'context' => ConsentContext::Profile, 'sender' => ConsentSender::Fishery, 'fishery_id' => $fishery->id,
        'channel' => ConsentChannel::Email, 'clause_version' => '1', 'occurred_at' => CarbonImmutable::now(),
    ]);

    expect($registry->marketingAllowed($user, ConsentSender::Fishery, ConsentChannel::Email, $fishery))->toBeTrue()
        ->and($registry->marketingAllowed($user, ConsentSender::Fishery, ConsentChannel::Sms, $fishery))->toBeFalse()
        ->and($registry->marketingAllowed($user, ConsentSender::Fishery, ConsentChannel::Email, Fishery::factory()->create()))->toBeFalse()
        ->and($registry->marketingAllowed($user, ConsentSender::Fisherya, ConsentChannel::Email))->toBeFalse()
        ->and(fn () => $event->update(['decision' => ConsentDecision::Withdrawn]))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    Date::setTestNow(CarbonImmutable::now()->addMinute());
    ConsentEvent::query()->create([
        'user_id' => $user->id, 'kind' => ConsentKind::Marketing, 'decision' => ConsentDecision::Withdrawn,
        'context' => ConsentContext::UnsubscribeLink, 'sender' => ConsentSender::Fishery, 'fishery_id' => $fishery->id,
        'channel' => ConsentChannel::Email, 'clause_version' => '1', 'occurred_at' => CarbonImmutable::now(),
    ]);

    expect($registry->marketingAllowed($user, ConsentSender::Fishery, ConsentChannel::Email, $fishery))->toBeFalse();

    $preferences = new NotificationPreferences;

    expect($preferences->allows($user, ConsentChannel::Email))->toBeTrue()
        ->and($preferences->allows($user, ConsentChannel::Sms))->toBeFalse()
        ->and($preferences->allows($user, ConsentChannel::Phone))->toBeFalse();
});
