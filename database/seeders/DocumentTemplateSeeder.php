<?php

namespace Database\Seeders;

use App\Enums\DocumentType;
use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Roboczy szablon regulaminu łowiska (zadanie 021).
 *
 * ⚠️ **DO WERYFIKACJI PRAWNEJ** — treść jest szkieletem, nie wzorem prawnym (TODO-1, TODO-3).
 * Za treść dokumentu łowiska odpowiada łowisko (D9). Szablonu polityki prywatności celowo nie ma:
 * jej treść zależy od rozstrzygnięcia ról w RODO (TODO-3, pytanie 6).
 *
 * Seeder jest idempotentny — nie nadpisuje szablonu poprawionego już w panelu.
 */
class DocumentTemplateSeeder extends Seeder
{
    public const WORKING_TERMS_NAME = 'Regulamin łowiska — wersja robocza (DO WERYFIKACJI PRAWNEJ)';

    public function run(): void
    {
        DocumentTemplate::query()->firstOrCreate(
            ['name' => self::WORKING_TERMS_NAME],
            [
                'type' => DocumentType::Terms,
                'content' => self::workingTerms(),
            ],
        );
    }

    private static function workingTerms(): string
    {
        return <<<'HTML'
<p><strong>⚠️ SZABLON ROBOCZY — WYMAGA WERYFIKACJI PRAWNEJ PRZED UŻYCIEM.</strong> Uzupełnij treść
zgodnie z zasadami swojego łowiska. Za treść regulaminu odpowiada łowisko.</p>
<h2>§ 1. Postanowienia ogólne</h2>
<ol>
<li>Regulamin określa zasady korzystania z łowiska [nazwa łowiska], prowadzonego przez [nazwa podmiotu, adres, NIP].</li>
<li>Zakup pozwolenia na łowienie oznacza akceptację regulaminu w wersji obowiązującej w chwili zakupu.</li>
</ol>
<h2>§ 2. Pobyt i doba wędkarska</h2>
<ol>
<li>Doba wędkarska trwa od godz. [..] do godz. [..] dnia następnego.</li>
<li>Wędkarz zajmuje stanowisko wskazane w potwierdzeniu zakupu.</li>
</ol>
<h2>§ 3. Zasady połowu</h2>
<ol>
<li>[Metody połowu, liczba wędek, zasady no-kill, wymagany sprzęt, limit zanęty.]</li>
</ol>
<h2>§ 4. Zasady pobytu</h2>
<ol>
<li>[Cisza nocna, ogniska, pojazdy, porządek na stanowisku, zwierzęta.]</li>
</ol>
<h2>§ 5. Odwołanie pobytu i zwroty</h2>
<ol>
<li>[Zasady odwołania pobytu i zwrotu płatności.]</li>
</ol>
<h2>§ 6. Postanowienia końcowe</h2>
<ol>
<li>Łowisko może zmienić regulamin; zmiana nie dotyczy pobytów kupionych przed jej wejściem w życie.</li>
</ol>
HTML;
    }
}
