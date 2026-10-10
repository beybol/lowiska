<?php

namespace App\Services;

use RuntimeException;

/**
 * Lista dotkniętych rezerwacji zmieniła się między ekranem decyzji a zapisem (nowa rezerwacja na tym
 * terminie). Zapis się wycofuje, a operator dostaje ekran decyzji od nowa — bez cichego pominięcia.
 */
final class StaleCollisionDecision extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The affected reservations changed — decide again.');
    }
}
