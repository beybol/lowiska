<?php

namespace App\Services;

/**
 * Skutek zapisu zmiany konfiguracji z decyzjami (zadanie 044): ile anulowano, ile zostawiono,
 * do ilu wędkarzy wyszedł mail, a ilu trafiło do „Do kontaktu".
 */
final readonly class CollisionResult
{
    public function __construct(
        public int $cancelled,
        public int $kept,
        public int $mailed,
        public int $notMailed,
    ) {}
}
