<?php

namespace App\Filament\Resources\PlatformDocumentResource\Pages;

use App\Filament\Resources\PlatformDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatformDocument extends CreateRecord
{
    protected static string $resource = PlatformDocumentResource::class;

    public function getTitle(): string
    {
        return __('New version of a Fisherya document');
    }

    /**
     * Standardowy CRUD wraca na listę, nie na widok edycji (`panel-admina.md` §4).
     */
    public function getRedirectUrl(): string
    {
        return PlatformDocumentResource::getUrl('index');
    }
}
