<?php

namespace App\Enums;

/**
 * Wynik pytania o zwrot przy odwołaniu pobytu przez wędkarza — `RefundPolicy` (zadanie 021).
 */
enum RefundOutcome: string
{
    /** Odwołanie przed pierwszą dobą — procent z progów (także 0%). */
    case Refund = 'refund';

    /** Łowisko nie ustawiło progów — ani 0%, ani 100%; co wtedy, rozstrzyga koszyk. */
    case PolicyNotSet = 'policy_not_set';

    /** Pierwsza doba już się zaczęła — to przerwanie pobytu, nie odwołanie. */
    case StayStarted = 'stay_started';
}
