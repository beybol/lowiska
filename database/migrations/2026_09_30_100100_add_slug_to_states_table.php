<?php

use App\Services\PortalSlugs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stały slug województwa — segment adresu kanonicznego łowiska (zadanie 030, portal-v3 §6.1).
 *
 * ⚠️ Slug jest POLSKI w obu językach portalu („wielkopolskie"), a nazwy w bazie są kluczami
 * tłumaczeń po angielsku — `PortalSlugs::forState()` liczy go z tłumaczenia polskiego.
 * Unikalny w obrębie kraju.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->string('slug', PortalSlugs::MAX_LENGTH)->nullable()->after('name');
        });

        DB::table('states')->orderBy('id')->select(['id', 'name', 'country_id'])->each(function (object $state): void {
            DB::table('states')
                ->where('id', $state->id)
                ->update(['slug' => PortalSlugs::forState($state->name, (int) $state->country_id, $state->id)]);
        });

        Schema::table('states', function (Blueprint $table) {
            $table->string('slug', PortalSlugs::MAX_LENGTH)->nullable(false)->change();
            $table->unique(['country_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('states', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
