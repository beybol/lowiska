<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Adres strony łowiska na Facebooku (zadanie 030).
 *
 * ⚠️ Host sprawdzany DOKŁADNIE, nie przez „kończy się na facebook.com" — inaczej przeszłoby
 * `evilfacebook.com`. Portal pokazuje ten link wędkarzom jako „Facebook łowiska".
 */
class FacebookUrl implements ValidationRule
{
    private const HOSTS = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com', 'www.fb.com'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $parts = parse_url((string) $value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || ! in_array($host, self::HOSTS, true)) {
            $fail(__('The :attribute must be a link to a Facebook page.'));
        }
    }
}
