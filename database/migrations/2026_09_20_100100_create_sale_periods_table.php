<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 015 — okresy sprzedaży łowiska.
 *
 * ⚠️ `fishery_id` jest WYMAGANY i kasuje się kaskadowo — świadome odstępstwo od
 * `positions`, gdzie klucz jest nullable z `set null`. Okres sprzedaży nie ma
 * wartości historycznej ani ścieżki dostępu poza łowiskiem (autoryzuje go
 * `FisheryPolicy`), więc osierocony wiersz byłby danymi, do których nic nie
 * prowadzi. Uzasadnienie: zadanie 015, „Rozstrzygnięcia".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['fishery_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_periods');
    }
};
