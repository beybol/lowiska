<?php

namespace App\Filament\Resources\DocumentTemplateResource\Pages;

use App\Filament\Resources\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ⚠️ `ListRecords` z pełnymi stronami, nie `ManageRecords` z modalem — treść szablonu to długi
 * tekst z edytora, który w modalu byłby ściśnięty (`panel-admina.md` §4).
 */
class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
