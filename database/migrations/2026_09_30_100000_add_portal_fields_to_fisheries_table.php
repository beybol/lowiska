<?php

use App\Services\PortalSlugs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dane łowiska dla portalu wędkarza — zadanie 030 (portal-v3 §6).
 *
 * ⚠️ Slug jest unikalny w CAŁYM portalu i obejmuje też łowiska usunięte miękko — dlatego
 * istniejące łowiska dostają go wszystkie, także z `deleted_at`, w kolejności `id`.
 *
 * ⚠️ `positions_count` znika: była drugą prawdą obok tabeli stanowisk. Liczbę stanowisk
 * wylicza się ze stanowisk w sprzedaży (`Position::available()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->string('slug', PortalSlugs::MAX_LENGTH)->nullable()->after('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('contact_hours')->nullable();
            $table->string('website_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->timestamp('published_at')->nullable();
        });

        DB::table('fisheries')->orderBy('id')->select(['id', 'name'])->each(function (object $fishery): void {
            DB::table('fisheries')
                ->where('id', $fishery->id)
                ->update(['slug' => PortalSlugs::forFishery($fishery->name, $fishery->id)]);
        });

        Schema::table('fisheries', function (Blueprint $table) {
            $table->string('slug', PortalSlugs::MAX_LENGTH)->nullable(false)->change();
            $table->unique('slug');
            $table->dropColumn('positions_count');
        });
    }

    public function down(): void
    {
        Schema::table('fisheries', function (Blueprint $table) {
            $table->integer('positions_count')->default(0);
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'phone', 'email', 'contact_hours', 'website_url', 'facebook_url', 'published_at']);
        });
    }
};
