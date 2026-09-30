<?php

/*
| Portal wędkarza — dane kontaktowe Fisherya pokazywane na stronach portalu (zadanie 031).
|
| Telefon jest opcjonalny: numer jest „do ustalenia" (makieta landingu) — bez wartości landing
| pokazuje sam e-mail.
*/

return [
    'contact_email' => env('PORTAL_CONTACT_EMAIL', 'kontakt@fisherya.com'),
    'contact_phone' => env('PORTAL_CONTACT_PHONE'),
];
