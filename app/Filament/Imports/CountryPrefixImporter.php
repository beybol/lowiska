<?php

namespace App\Filament\Imports;

use App\Models\CountryPrefix;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class CountryPrefixImporter extends Importer
{
    protected static ?string $model = CountryPrefix::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('prefix')
                ->label(__('Country prefix'))
                ->rules(['max:255']),
            ImportColumn::make('country_name')
                ->label(__('Country name'))
                ->rules(['max:255']),
        ];
    }

    public function resolveRecord(): ?CountryPrefix
    {
        return new CountryPrefix();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = __('Imported rows count') . ' ' . $import->successful_rows . '.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' ' . __('failed to import.');
        }

        return $body;
    }
}
