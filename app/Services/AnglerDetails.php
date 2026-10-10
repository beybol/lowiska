<?php

namespace App\Services;

/**
 * Dane wędkarza podane przez operatora przy rezerwacji (ADR-024) — trafiają do `given_*` relacji
 * łowiska, nigdy do `users`. E-mail jest opcjonalny: rezerwacja z samym telefonem zostaje relacją
 * niepowiązaną.
 */
final readonly class AnglerDetails
{
    public function __construct(
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $notes = null,
    ) {}
}
