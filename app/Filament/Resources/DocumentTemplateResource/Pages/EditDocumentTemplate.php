<?php

namespace App\Filament\Resources\DocumentTemplateResource\Pages;

use App\Filament\Resources\DocumentTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * ⚠️ Zmiana i usunięcie szablonu NIE ruszają dokumentów łowisk — wersja dostała kopię treści,
 * bez klucza obcego do szablonu (ADR-017).
 */
class EditDocumentTemplate extends EditRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit document template');
    }

    public function getRedirectUrl(): string
    {
        return DocumentTemplateResource::getUrl('index');
    }
}
