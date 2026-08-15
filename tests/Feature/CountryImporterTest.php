<?php

namespace Tests\Feature;

use App\Filament\Resources\CountryResource\Pages\ListCountries;
use App\Models\Country;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/**
 * Regresja dla ADR-004 / zadanie 003: mechanizm importu Filamenta kolejkuje pracę
 * (Filament\Actions\Imports\Jobs\ImportCsv), więc bez konsumenta kolejki import
 * nigdy by się nie domknął. `phpunit.xml` wymusza QUEUE_CONNECTION=sync dla całego
 * pakietu — dokładnie tę konfigurację ma mieć środowisko Cloud Run (ADR-004).
 */
test('country import completes synchronously and reports failed rows', function () {
    $admin = $this->createSuperAdmin();

    $csv = "prefix,country_name\n"
        .str_repeat('X', 300).",Zbyt długi prefiks\n"
        ."PL,Poland\n";

    $file = UploadedFile::fake()->createWithContent('countries.csv', $csv);

    Livewire::actingAs($admin)
        ->test(ListCountries::class)
        ->mountTableAction('import')
        ->setTableActionData([
            'file' => $file,
            'columnMap' => [
                'prefix' => 'prefix',
                'country_name' => 'country_name',
            ],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    $importRecord = Import::query()->latest('id')->first();

    expect($importRecord)->not->toBeNull();
    expect($importRecord->completed_at)->not->toBeNull();
    expect($importRecord->total_rows)->toBe(2);
    expect($importRecord->processed_rows)->toBe(2);
    expect($importRecord->successful_rows)->toBe(1);
    expect($importRecord->failedRows()->count())->toBe(1);

    expect(Country::query()->where('country_name', 'Poland')->exists())->toBeTrue();
});
