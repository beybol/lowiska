<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBatchUuidColumnToActivityLogTable extends Migration
{
    /**
     * ⚠️ Zadanie 010: literały zamiast `config('activitylog.*')` — patrz
     * komentarz w 2025_08_01_092224_create_activity_log_table.php. Kolumna
     * `batch_uuid` dodana tutaj jest usuwana kolejną migracją
     * (`…_migrate_activity_log_to_v5_schema.php`) — system wsadów wycofany
     * w v5, a projekt nigdy go nie używał (zweryfikowane w zadaniu 010).
     */
    public function up()
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });
    }

    public function down()
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('batch_uuid');
        });
    }
}
