<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateActivityLogTable extends Migration
{
    /**
     * ⚠️ Zadanie 010: `config('activitylog.table_name')` i `database_connection`
     * zastąpione literałami — v5 usunął te klucze konfiguracji (własny model
     * `Activity` ma dziś `protected $table = 'activity_log'` na sztywno), więc
     * odwołanie do nieistniejącego klucza zwracałoby `null` i wywaliłoby
     * `Schema::create(null, ...)`. Zmiana jest behawioralnie neutralna: obie
     * zmienne środowiskowe nigdy nie były w tym projekcie ustawione, więc
     * `config()` i tak zawsze rozwiązywało się do tych samych wartości.
     */
    public function up()
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
    }

    public function down()
    {
        Schema::dropIfExists('activity_log');
    }
}
