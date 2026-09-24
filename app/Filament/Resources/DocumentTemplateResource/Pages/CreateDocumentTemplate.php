<?php

namespace App\Filament\Resources\DocumentTemplateResource\Pages;

use App\Filament\Resources\DocumentTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentTemplate extends CreateRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    public function getTitle(): string
    {
        return __('Create document template');
    }

    /**
     * Standardowy CRUD wraca na listę, nie na widok edycji (`panel-admina.md` §4).
     */
    public function getRedirectUrl(): string
    {
        return DocumentTemplateResource::getUrl('index');
    }
}
