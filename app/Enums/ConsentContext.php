<?php

namespace App\Enums;

/** Gdzie zapadła decyzja zapisana w rejestrze zgód (ADR-028). */
enum ConsentContext: string
{
    /** Założenie konta — `/register`, rejestracja w panelu, nowe konto przez dostawcę logowania. */
    case Registration = 'registration';

    /** Połączenie konta z łowiskiem linkiem z maila o rezerwacji (zadanie 049). */
    case AnglerLink = 'angler_link';

    /** Profil wędkarza — tabela zgód (kolejne zadanie). */
    case Profile = 'profile';

    /** Link wypisu w wiadomości marketingowej (kolejne zadanie). */
    case UnsubscribeLink = 'unsubscribe_link';

    /** Zakup (etap 4). */
    case Purchase = 'purchase';
}
