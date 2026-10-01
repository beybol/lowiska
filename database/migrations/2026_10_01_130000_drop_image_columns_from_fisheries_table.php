<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zdjęcia łowiska przechodzą do medialibrary (zadanie 036, ADR-023 — opcja B).
 *
 * ⚠️ **Bez przenoszenia danych** — decyzja autora z 01.10.2026: zdjęcia Klasztornego i Łopienna wgrywa się
 * ponownie po wdrożeniu. Pliki ze starych ścieżek zostają w buckecie, nikt już na nie nie wskazuje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->dropColumn(['map_image_path', 'gallery_images']);
        });
    }

    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->string('map_image_path')->nullable();
            $table->json('gallery_images')->nullable();
        });
    }
};
