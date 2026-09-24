<?php

namespace App\Enums;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jak konto się loguje — kolumna i filtr „Logowanie" na liście kont w `/admin` (zadanie 028).
 *
 * ⚠️ Wynika z DWÓCH kolumn: `provider` (powiązanie z dostawcą) i `has_password` (czy człowiek
 * ustawił hasło). Konto założone przez Google ma hasło losowe, więc samo `provider` nie wystarcza
 * do odróżnienia „Google" od „Google + hasło". Jeden dom reguły — ta klasa.
 */
enum SignInMethod: string
{
    /** Dostawca, którego dowiązanie jest dziś możliwe — potwierdza adres (ADR-019). */
    private const LINKABLE_PROVIDER = 'google';

    /** Konto bez dostawcy — wyłącznie hasło. */
    case Password = 'password';

    /** Konto z dostawcą i bez znanego hasła (założone przez dostawcę albo przejęte jako niezweryfikowane). */
    case Provider = 'provider';

    /** Konto z dostawcą i ze znanym hasłem (dowiązane albo z hasłem ustawionym później). */
    case ProviderAndPassword = 'provider_and_password';

    public static function of(User $user): self
    {
        if ($user->provider === null) {
            return self::Password;
        }

        return $user->has_password ? self::ProviderAndPassword : self::Provider;
    }

    public function labelFor(?string $provider): string
    {
        $providerName = self::providerName($provider);

        return match ($this) {
            self::Password => __('Password'),
            self::Provider => $providerName,
            self::ProviderAndPassword => __(':provider + password', ['provider' => $providerName]),
        };
    }

    /**
     * Wartości filtra — te same etykiety co w kolumnie. Dowiązać da się wyłącznie dostawcę, który
     * potwierdza adres (ADR-019), czyli dziś Google, więc filtr nazywa go wprost.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->labelFor(self::LINKABLE_PROVIDER);
        }

        return $options;
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scope(Builder $query): Builder
    {
        return match ($this) {
            self::Password => $query->whereNull('provider'),
            self::Provider => $query->whereNotNull('provider')->where('has_password', false),
            self::ProviderAndPassword => $query->whereNotNull('provider')->where('has_password', true),
        };
    }

    /**
     * Nazwa dostawcy do pokazania użytkownikowi — jedyne miejsce tej mapy (kolumna, filtr,
     * komunikat po przejęciu konta w `SocialAuthController`).
     */
    public static function providerName(?string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            'facebook' => 'Facebook',
            default => ucfirst((string) $provider),
        };
    }
}
