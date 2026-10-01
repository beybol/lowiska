<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;

/**
 * Liczba mnoga w angielskiej i polskiej wersji (zadanie 037).
 *
 * ⚠️ Kluczem tłumaczenia jest angielski tekst, a pliku `lang/en.json` nie ma. `trans_choice()` dla klucza
 * nieobecnego w bieżącym języku bierze język ZAPASOWY — przy `fallback_locale = pl` liczył więc liczbę
 * mnogą po polsku także na stronach EN. Te testy pilnują, że język zapasowy to `en` i że żaden klucz
 * z liczbą mnogą w kodzie nie wychodzi w złym języku.
 */

/**
 * Klucze z liczbą mnogą użyte w kodzie — z `trans_choice()` i `@choice()`.
 *
 * @return array{keys: list<string>, calls: int}
 */
function pluralKeysInCode(): array
{
    $keys = [];
    $calls = 0;

    foreach (['app', 'resources'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = $file->getContents();
            $calls += preg_match_all('/(?:trans_choice\(|@choice\()/', $source);

            if (preg_match_all('/(?:trans_choice\(|@choice\()\s*([\'"])((?:(?!\1)[^\\\\]|\\\\.)*)\1/s', $source, $matches)) {
                foreach ($matches[2] as $key) {
                    $keys[] = stripcslashes($key);
                }
            }
        }
    }

    return ['keys' => array_values(array_unique($keys)), 'calls' => $calls];
}

test('the fallback locale is English, so a plural key is counted in English', function () {
    expect(config('app.fallback_locale'))->toBe('en');
});

test('every plural key is a literal in the code', function () {
    // Klucz budowany w locie nie da się sprawdzić tym testem — wtedy musi mieć własny.
    ['keys' => $keys, 'calls' => $calls] = pluralKeysInCode();

    expect($keys)->not->toBeEmpty()
        ->and($calls)->toBeGreaterThanOrEqual(count($keys));

    $literalCalls = 0;

    foreach (['app', 'resources'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $literalCalls += preg_match_all('/(?:trans_choice\(|@choice\()\s*[\'"]/', $file->getContents());
            }
        }
    }

    expect($literalCalls)->toBe($calls, 'Jest trans_choice()/@choice() z kluczem budowanym w locie — test go nie sprawdzi.');
});

test('in English every plural key gives its own English forms', function () {
    app()->setLocale('en');

    foreach (pluralKeysInCode()['keys'] as $key) {
        $forms = explode('|', $key);

        $this->assertSame(str_replace(':count', '1', $forms[0]), trans_choice($key, 1, ['count' => 1]), "Klucz „{$key}” dla 1 w języku en");
        $this->assertSame(str_replace(':count', '5', $forms[count($forms) - 1]), trans_choice($key, 5, ['count' => 5]), "Klucz „{$key}” dla 5 w języku en");
    }
});

test('in Polish every plural key has the three forms from the language file', function () {
    app()->setLocale('pl');
    $pl = json_decode((string) file_get_contents(base_path('lang/pl.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (pluralKeysInCode()['keys'] as $key) {
        $this->assertArrayHasKey($key, $pl, "Klucz z liczbą mnogą „{$key}” nie ma polskiej wersji w lang/pl.json");

        $forms = explode('|', $pl[$key]);

        $this->assertCount(3, $forms, "Polska wersja „{$key}” musi mieć trzy formy (1 / 2–4 / 5+)");
        $this->assertSame(str_replace(':count', '1', $forms[0]), trans_choice($key, 1, ['count' => 1]), "Klucz „{$key}” dla 1 w języku pl");
        $this->assertSame(str_replace(':count', '2', $forms[1]), trans_choice($key, 2, ['count' => 2]), "Klucz „{$key}” dla 2 w języku pl");
        $this->assertSame(str_replace(':count', '5', $forms[2]), trans_choice($key, 5, ['count' => 5]), "Klucz „{$key}” dla 5 w języku pl");
    }
});

test('a plural key in the code can not be missed — the extractor finds the known ones', function () {
    expect(pluralKeysInCode()['keys'])
        ->toContain(':count position|:count positions')
        ->toContain('with :count angler|with :count anglers')
        ->toContain('min. :count night|min. :count nights');
});
