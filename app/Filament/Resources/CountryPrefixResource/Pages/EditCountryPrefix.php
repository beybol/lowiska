<?php

namespace App\Filament\Resources\CountryPrefixResource\Pages;

use App\Filament\Resources\CountryPrefixResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCountryPrefix extends EditRecord
{
    protected static string $resource = CountryPrefixResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
