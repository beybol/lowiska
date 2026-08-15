<?php

use App\Services\ActivityLogSchemaMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zadanie 010: dziennik zmian ze `spatie/laravel-activitylog` 4.x na 5.x.
 *
 * Dokłada kolumnę `attribute_changes` (v5 śledzi w niej zmiany atrybutów
 * osobno od własnych danych w `properties`), przenosi tam dane historyczne
 * z `properties.attributes`/`properties.old`, i usuwa `batch_uuid` — system
 * wsadów wycofany w v5, którego ten projekt nigdy nie używał.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->json('attribute_changes')->nullable()->after('causer_id');
        });

        DB::table('activity_log')
            ->whereNotNull('properties')
            ->orderBy('id')
            ->eachById(function (object $row) {
                $properties = json_decode($row->properties, true) ?? [];
                $split = ActivityLogSchemaMigrator::splitProperties($properties);

                DB::table('activity_log')->where('id', $row->id)->update([
                    'attribute_changes' => $split['attribute_changes'] === null
                        ? null
                        : json_encode($split['attribute_changes']),
                    'properties' => $split['properties'] === null
                        ? null
                        : json_encode($split['properties']),
                ]);
            });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });

        DB::table('activity_log')
            ->whereNotNull('attribute_changes')
            ->orderBy('id')
            ->eachById(function (object $row) {
                $properties = json_decode($row->properties, true) ?? [];
                $changes = json_decode($row->attribute_changes, true) ?? [];
                $merged = array_merge($properties, $changes);

                DB::table('activity_log')->where('id', $row->id)->update([
                    'properties' => $merged === [] ? null : json_encode($merged),
                ]);
            });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('attribute_changes');
        });
    }
};
