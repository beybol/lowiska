<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Szablon dokumentu z panelu admina (zadanie 021, ADR-017).
 *
 * ⚠️ Typ PORZĄDKUJE i PODPOWIADA, nie ogranicza — przy nowej wersji dokumentu wolno wybrać
 * dowolny szablon. Wersja dostaje KOPIĘ treści, bez klucza obcego, więc zmiana albo usunięcie
 * szablonu nie rusza dokumentów łowisk.
 */
class DocumentTemplate extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'type',
        'name',
        'content',
    ];

    protected $casts = [
        'type' => DocumentType::class,
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable);
    }
}
