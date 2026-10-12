<?php

namespace App\Filament\Resources\PlatformDocumentResource\Pages;

use App\Filament\Resources\PlatformDocumentResource;
use Filament\Resources\Pages\EditRecord;

/**
 * ⚠️ Wersja obowiązująca: formularz ma wyłączone pola treści, a gdyby przysłał więcej — odrzuci to model
 * po dacie z bazy (`Document::booted()`). Edytowalna zostaje flaga wymagalności.
 */
class EditPlatformDocument extends EditRecord
{
    protected static string $resource = PlatformDocumentResource::class;

    public function getTitle(): string
    {
        return __('Edit a Fisherya document version');
    }

    public function getRedirectUrl(): string
    {
        return PlatformDocumentResource::getUrl('index');
    }
}
