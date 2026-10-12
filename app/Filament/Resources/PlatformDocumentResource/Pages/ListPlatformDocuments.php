<?php

namespace App\Filament\Resources\PlatformDocumentResource\Pages;

use App\Filament\Resources\PlatformDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ⚠️ `ListRecords` z pełnymi stronami — treść dokumentu to długi tekst z edytora (`panel-admina.md` §4).
 */
class ListPlatformDocuments extends ListRecords
{
    protected static string $resource = PlatformDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New version')),
        ];
    }
}
