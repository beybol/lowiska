<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Preferencje powiadomień technicznych — stan bieżący, nie zgoda (zadanie 049, ADR-028 §3a). Wspólne dla
 * całego systemu, bez podziału na Fisherya i łowiska. E-mail nie ma kolumny: jest zawsze włączony.
 * Odczyt wyłącznie przez `NotificationPreferences`; interfejs w profilu — kolejne zadanie.
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'sms',
        'push',
    ];

    protected $casts = [
        'sms' => 'boolean',
        'push' => 'boolean',
    ];
}
