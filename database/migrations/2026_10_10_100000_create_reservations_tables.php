<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rdzeń rezerwacji — zadanie 039 (ADR-024, ADR-025, ADR-026).
 *
 * Pięć tabel w jednej rewizji:
 * - `fishery_anglers` — relacja łowisko ↔ wędkarz; rezerwacja wskazuje relację, nigdy `users` (ADR-024);
 * - `reservations` — rezerwacja z historią (anulowana i wygasła zostają);
 * - `reservation_slots` — zajętość stanowiska jako przedziały `[starts_at, ends_at)` w UTC (ADR-025);
 * - `reservation_terms` — zamrożone warunki, 1:1 (ADR-026);
 * - `reservation_service_items` — zajętość egzemplarzy usług z limitem (ADR-025 pkt 7).
 *
 * ⚠️ `unique(position_id, starts_at)` na slotach to SIATKA BEZPIECZEŃSTWA, nie gwarancja: MySQL nie
 * ma ograniczenia wykluczającego przedziały, więc brak nakładania zapewnia blokada wiersza
 * stanowiska i ponowne sprawdzenie w `ReservationBooking`. Nie usuwaj tego indeksu „bo kod pilnuje".
 *
 * ⚠️ Momenty są w UTC (rzut `UtcDateTime`) — strefą aplikacji jest `Europe/Warsaw`.
 * Przedział pobytu (`starts_at`, `ends_at`, `first_night_on`, `nights`) jest powtórzony
 * na rezerwacji, bo anulacja usuwa sloty, a historia ma nadal pokazywać termin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fishery_anglers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('unlinked');
            $table->string('given_name');
            $table->string('given_email')->nullable();
            // Znormalizowany adres (trim + małe litery) — klucz unikalności w łowisku (ADR-024 pkt 1).
            $table->string('given_email_normalized')->nullable();
            $table->string('given_phone', 32)->nullable();
            $table->string('phone_override', 32)->nullable();
            $table->text('notes')->nullable();
            $table->string('link_token_hash', 64)->nullable()->unique();
            $table->dateTime('link_expires_at')->nullable();
            $table->dateTime('linked_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            // MySQL dopuszcza wiele NULL-i w indeksie unikalnym — oba warunki dotyczą wartości niepustych.
            $table->unique(['fishery_id', 'user_id']);
            $table->unique(['fishery_id', 'given_email_normalized']);
            $table->index(['fishery_id', 'given_phone']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fishery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->foreignId('fishery_angler_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->string('payment_status')->default('unpaid');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->date('first_night_on');
            $table->unsignedSmallInteger('nights');
            $table->dateTime('confirm_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('confirmation_source')->nullable();
            $table->string('source');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_note')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->text('cancel_message')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('needs_contact_at')->nullable();
            $table->dateTime('contacted_at')->nullable();
            $table->string('manage_token_hash', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['fishery_id', 'status']);
            $table->index(['fishery_id', 'starts_at']);
            $table->index(['position_id', 'starts_at']);
            $table->index(['status', 'confirm_by']);
        });

        Schema::create('reservation_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('slot_type');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->unique(['position_id', 'starts_at']);
            $table->index(['position_id', 'ends_at']);
        });

        Schema::create('reservation_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('schema_version');
            $table->string('currency', 16)->nullable();
            $table->unsignedSmallInteger('anglers');
            $table->unsignedSmallInteger('companions');
            // Kwoty w groszach. Wyliczona przez system bywa pusta (brak ceny przy cenie uzgodnionej).
            $table->integer('stay_total_cents')->nullable();
            $table->integer('services_total_cents')->default(0);
            $table->integer('computed_total_cents')->nullable();
            $table->integer('agreed_total_cents')->nullable();
            $table->integer('total_cents');
            $table->json('price_breakdown')->nullable();
            $table->json('refund_policy')->nullable();
            $table->json('position_snapshot');
            $table->json('services');
            $table->json('documents');
            $table->json('exceptions');
            $table->json('contact_snapshot');
            $table->timestamps();
        });

        Schema::create('reservation_service_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('additional_service_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            $table->index(['additional_service_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_service_items');
        Schema::dropIfExists('reservation_terms');
        Schema::dropIfExists('reservation_slots');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('fishery_anglers');
    }
};
