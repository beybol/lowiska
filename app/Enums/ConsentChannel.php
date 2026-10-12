<?php

namespace App\Enums;

/**
 * Kanał komunikacji (ADR-028 §3 i §3a). Push bez rozróżnienia przeglądarki i aplikacji — zgoda dotyczy
 * kanału, a subskrypcja urządzenia to osobny rekord techniczny.
 */
enum ConsentChannel: string
{
    case Email = 'email';

    case Sms = 'sms';

    case Push = 'push';

    /** Wyłącznie zgoda marketingowa — rozmowy technicznej system nie wysyła, prowadzi ją człowiek. */
    case Phone = 'phone';
}
