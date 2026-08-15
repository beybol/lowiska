<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

/**
 * Regresja dla ADR-003 / zadanie 002: trustProxies(at: '*') musi zawsze mieć jawną
 * maskę headers:. Bez niej domyślna maska Laravela ufa X-Forwarded-Host, co przy
 * at: '*' pozwala dowolnemu klientowi dyktować host, z którego budowane są adresy
 * absolutne i podpisy URL-i (zatrucie hosta, CWE-644).
 *
 * ⚠️ Zweryfikowane negatywnie: po tymczasowym cofnięciu maski (dopisaniu
 * Request::HEADER_X_FORWARDED_HOST do argumentu headers: w bootstrap/app.php)
 * testy „nie zmienia adresów absolutnych" i „nie zmienia adresów podpisanych"
 * czerwienieją, a test X-Forwarded-Proto zostaje zielony — patrz docs/security/.
 */
function realAppHost(): string
{
    return parse_url(config('app.url'), PHP_URL_HOST);
}

test('X-Forwarded-Proto: https czyni żądanie bezpiecznym i przepisuje schemat adresów', function () {
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/');

    expect(request()->isSecure())->toBeTrue();
    expect(url('/'))->toStartWith('https://');
    expect(asset('build/manifest.json'))->toStartWith('https://');
});

test('X-Forwarded-For jest odczytywany jako adres klienta', function () {
    $this->withHeaders(['X-Forwarded-For' => '203.0.113.7'])->get('/');

    expect(request()->ip())->toBe('203.0.113.7');
});

test('X-Forwarded-Host nie zmienia adresów absolutnych', function () {
    $this->withHeaders(['X-Forwarded-Host' => 'evil.tld'])->get('/');

    $url = url('/');
    $asset = asset('build/manifest.json');

    expect($url)->not->toContain('evil.tld');
    expect($asset)->not->toContain('evil.tld');
    expect(parse_url($url, PHP_URL_HOST))->toBe(realAppHost());
});

test('X-Forwarded-Host nie zmienia adresów podpisanych — podpis nie chroni przed zatruciem hosta', function () {
    Notification::fake();

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->withHeaders(['X-Forwarded-Host' => 'evil.tld'])
        ->post('/email/verification-notification');

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
        $mail = $notification->toMail($user);
        $signedUrl = $mail->viewData['url'];

        expect($signedUrl)->not->toContain('evil.tld');
        expect(parse_url($signedUrl, PHP_URL_HOST))->toBe(realAppHost());

        return true;
    });
});
