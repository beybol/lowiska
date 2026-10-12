<?php

namespace App\Enums;

/** Decyzja zapisana w rejestrze zgód (ADR-028). Stan bieżący to ostatnie zdarzenie dla przedmiotu. */
enum ConsentDecision: string
{
    case Granted = 'granted';

    case Withdrawn = 'withdrawn';
}
