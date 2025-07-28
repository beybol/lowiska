<?php

namespace App\Filament\Resources\CountryPrefixResource\Pages;

use App\Filament\Resources\CountryPrefixResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\ImportAction;
use App\Filament\Importers\CountryPrefixImporter;

class ListCountryPrefixes extends ListRecords
{
    protected static string $resource = CountryPrefixResource::class;
}
