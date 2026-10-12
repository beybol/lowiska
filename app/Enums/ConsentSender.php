<?php

namespace App\Enums;

/**
 * Nadawca wiadomości marketingowych (ADR-028 §3). Stały dla całego portalu — łowisko niczego
 * nie konfiguruje.
 *
 * ⚠️ `Partners` = treść partnerów wysyłana WYŁĄCZNIE przez Fisherya; dane nie trafiają do partnerów.
 * ⚠️ `Fishery` zapisuje się per łowisko (`fishery_id`) — każde łowisko jest odrębnym administratorem
 * danych, a nowe łowisko nie dziedziczy zgody.
 */
enum ConsentSender: string
{
    case Fisherya = 'fisherya';

    case Partners = 'partners';

    case Fishery = 'fishery';
}
