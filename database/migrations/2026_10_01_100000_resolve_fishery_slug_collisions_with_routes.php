<?php

use App\Services\PortalSlugs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Slug łowiska staje się krótkim adresem wprost pod domeną — zadanie 031, ADR-021 opcja B.
 *
 * Slugi nadane w 030 powstały, zanim slug musiał omijać adresy aplikacji (minimum 3 znaki, lista
 * zastrzeżona, trasy). Slug kolidujący dostaje sufiks, tak samo jak przy nadawaniu automatycznym.
 * Nic nie jest jeszcze wydrukowane ani opublikowane, więc zmiana jest bezpieczna TYLKO teraz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('fisheries')->orderBy('id')->select(['id', 'slug'])->each(function (object $fishery): void {
            if (! PortalSlugs::collidesWithApplication((string) $fishery->slug)) {
                return;
            }

            DB::table('fisheries')
                ->where('id', $fishery->id)
                ->update(['slug' => PortalSlugs::forFishery((string) $fishery->slug, $fishery->id)]);
        });
    }

    public function down(): void
    {
        // Poprzedni slug był kolizją z adresem aplikacji — nie ma do czego wracać.
    }
};
