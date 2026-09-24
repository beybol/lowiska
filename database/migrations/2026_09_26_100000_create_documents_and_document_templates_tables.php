<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dokumenty łowiska (regulamin, polityka prywatności) i szablony z panelu admina — zadanie 021.
 *
 * Kształt: ADR-017, wariant opcji B — jedna tabela wersji. ⚠️ Jedynym kluczem głównym obu tabel
 * jest `id`; nie ma indeksu unikalnego na kilku kolumnach. Zakaz dwóch wersji jednego rodzaju
 * z tą samą datą pilnuje reguła `DocumentEffectiveDateIsFree`, pomijająca wersje usunięte miękko.
 *
 * ⚠️ `fishery_id` jest WYMAGANY i BEZ kaskady: wersja może być wskazana przez przyszłą
 * transakcję (G1, Z1), więc nie może zniknąć razem z łowiskiem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained();
            $table->string('type');
            $table->string('title');
            $table->date('effective_from');
            $table->longText('content');
            $table->boolean('required_at_purchase')->default(false);
            $table->boolean('required_at_registration')->default(false);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['fishery_id', 'type', 'effective_from']);
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('name');
            $table->longText('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('documents');
    }
};
