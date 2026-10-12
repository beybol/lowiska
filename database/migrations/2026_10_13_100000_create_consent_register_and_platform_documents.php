<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rejestr zgód i akceptacji oraz dokumenty Fisherya — zadanie 049, ADR-028.
 *
 * - `documents.fishery_id` nullowalny: `NULL` = dokument Fisherya (globalny), ta sama reguła wersji co
 *   u łowiska. `market_country_id` — opcjonalny aneks rynkowy (rynek = kraj łowiska), na start pusty.
 *   `locale` — język treści wiążącej; warianty w innych językach w `document_translations`.
 *   `change_kind` + `announced_on` — rodzaj zmiany i data ogłoszenia (ADR-028 §2).
 * - `consent_events` — tylko dopisywanie, bez kaskady: wiersze są dowodem (kto, co, którą wersję
 *   i wariant językowy, kiedy, gdzie). Stan bieżący to ostatnie zdarzenie dla przedmiotu.
 * - `notification_preferences` — preferencje powiadomień technicznych, wyłącznie stan bieżący
 *   (to nie zgoda, ADR-028 §3a). E-mail nie ma kolumny, bo jest zawsze włączony.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('fishery_id')->nullable()->change();
            $table->foreignId('market_country_id')->nullable()->after('fishery_id')->constrained('countries');
            $table->string('locale', 5)->default('pl')->after('type');
            $table->string('change_kind')->default('notice')->after('effective_from');
            $table->date('announced_on')->nullable()->after('change_kind');
        });

        Schema::create('document_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained();
            $table->string('locale', 5);
            $table->string('title');
            $table->longText('content');
            $table->timestamps();

            $table->unique(['document_id', 'locale']);
        });

        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind');
            $table->string('decision');
            $table->string('context');
            $table->foreignId('document_id')->nullable()->constrained();
            $table->string('locale', 5)->nullable();
            $table->string('sender')->nullable();
            $table->foreignId('fishery_id')->nullable()->constrained();
            $table->string('channel')->nullable();
            $table->string('clause_version')->nullable();
            $table->foreignId('fishery_angler_id')->nullable()->constrained();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['user_id', 'kind']);
            $table->index(['user_id', 'sender', 'channel']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->boolean('sms')->default(false);
            $table->boolean('push')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('consent_events');
        Schema::dropIfExists('document_translations');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('market_country_id');
            $table->dropColumn(['locale', 'change_kind', 'announced_on']);
        });
    }
};
