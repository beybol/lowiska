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
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Password->value => __('Password'),
            self::Provider->value => __('Provider only'),
            self::ProviderAndPassword->value => __('Provider + password'),
        ];
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

    private static function providerName(?string $provider): string
    {
        return match ($provider) {
            'google' => 'Google',
            'facebook' => 'Facebook',
            default => ucfirst((string) $provider),
        };
    }
}
