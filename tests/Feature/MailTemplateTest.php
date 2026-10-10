<?php

namespace Tests\Feature;

use App\Mail\MailPreviews;
use App\Mail\MailTheme;
use App\Mail\PortalMailable;
use App\Mail\TwoFactorCodeMail;
use App\Mail\VerifyEmailMail;
use App\Models\Fishery;
use App\Models\User;
use App\Notifications\SendTwoFactorCode;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Blade;

/**
 * Wspólny szablon maili (zadanie 040): layout, komponenty, wariant tekstowy, podgląd.
 */
test('every registered mail renders an HTML and a text variant on the shared layout', function () {
    foreach (MailPreviews::all() as $key => $factory) {
        $mail = $factory();
        $html = $mail->render();
        $text = $mail->renderText();

        expect($html)->toContain(MailTheme::BRAND)
            ->and($html)->toContain('role="presentation"')
            ->and($text)->not->toBe('')
            // Wariant tekstowy to tekst: bez znaczników i bez encji HTML.
            ->and($text)->not->toContain('<table')
            ->and($text)->not->toContain('&amp;')
            ->and($mail->headers()->text['X-Fisherya-Mail'])->toBe($key);
    }
});

test('the verify-email mail keeps its content on the new layout', function () {
    app()->setLocale('en');
    $mail = new VerifyEmailMail('https://example.test/verify/abc');

    expect($mail->render())
        ->toContain('Verify e-mail address')
        ->toContain('Thank you for registering! Click the button below to verify your email address.')
        ->toContain('https://example.test/verify/abc')
        ->toContain('If you did not create an account, no further action is required.')
        ->and($mail->renderText())->toContain('https://example.test/verify/abc');
});

test('the registration notification builds the shared-layout mail', function () {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail)->toBeInstanceOf(VerifyEmailMail::class)
        ->and($mail->hasTo($user->email))->toBeTrue()
        ->and($mail->url)->toContain('verify-email');
});

test('the two-factor notification builds the shared-layout mail with the code', function () {
    $user = User::factory()->create(['two_factor_code' => '654321']);

    $mail = (new SendTwoFactorCode)->toMail($user);

    expect($mail)->toBeInstanceOf(TwoFactorCodeMail::class)
        ->and($mail->hasTo($user->email))->toBeTrue()
        ->and($mail->code)->toBe('654321')
        ->and($mail->render())->toContain('654321');
});

test('sending the verification notification delivers a multipart message', function () {
    config(['mail.default' => 'array']);
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $email = $messages->last()->getOriginalMessage();

    expect($messages)->toHaveCount(1)
        ->and($email->getHtmlBody())->toContain(MailTheme::BRAND)
        ->and($email->getTextBody())->toContain('verify-email')
        ->and($email->getHeaders()->get('X-Fisherya-Mail')?->getBodyAsString())->toBe('verify-email');
});

test('the subject carries the brand prefix, the fishery name and the recipient language', function () {
    $fishery = Fishery::factory()->make(['name' => 'Łowisko Pod Lasem']);
    $mail = new class($fishery, 'pl') extends PortalMailable
    {
        public static function key(): string
        {
            return 'test';
        }

        protected function subjectLine(): string
        {
            return __('Verify e-mail address');
        }

        protected function htmlView(): string
        {
            return 'emails.verify-email';
        }

        protected function textView(): string
        {
            return 'emails.verify-email-text';
        }

        protected function viewData(): array
        {
            return ['url' => 'https://example.test'];
        }
    };

    app()->setLocale('en');

    expect($mail->prefixedSubject())->toBe('Fisherya · Łowisko Pod Lasem — '.trans('Verify e-mail address', [], 'pl'))
        ->and((new VerifyEmailMail('x', 'en'))->prefixedSubject())->toBe('Fisherya — Verify e-mail address')
        // Język spoza portalu → bieżący język aplikacji.
        ->and((new VerifyEmailMail('x', 'de'))->locale)->toBe('en')
        ->and($mail->render())->toContain('Łowisko Pod Lasem');
});

test('components escape the fishery message and hide empty details', function () {
    $html = Blade::render(
        '<x-mail.fishery-message :message="$message" from="Łowisko" /><x-mail.details :rows="$rows" title="Szczegóły" />',
        ['message' => "<script>alert(1)</script>\nDruga linia", 'rows' => ['Termin' => '13.05', 'Pusta' => null]],
    );

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('<br />')
        ->and($html)->toContain('Termin')
        ->and($html)->not->toContain('Pusta');
});

test('the preview lists and renders every mail in the testing environment', function () {
    $this->get(route('dev.mail-preview.index'))->assertOk()->assertSee('verify-email');

    foreach (array_keys(MailPreviews::all()) as $key) {
        $this->get(route('dev.mail-preview', ['mail' => $key]))->assertOk()->assertSee(MailTheme::BRAND);
        $this->get(route('dev.mail-preview', ['mail' => $key, 'format' => 'text']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    $this->get('/dev/mail-preview/nonexistent')->assertNotFound();
});

test('the preview is unavailable in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(MailPreviews::allowed())->toBeFalse();

    $this->get('/dev/mail-preview')->assertNotFound();
    $this->get('/dev/mail-preview/verify-email')->assertNotFound();
});
