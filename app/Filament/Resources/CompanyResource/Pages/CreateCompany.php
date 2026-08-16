<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * ⚠️ Do zadania 012 ta strona pełniła podwójną rolę: zwykłego formularza firmy
 * oraz „kroku 1" ręcznego kreatora zakładania łowiska (tryb `?wizard=1`, własny
 * nagłówek z gołym `<select>`, przekierowania przez `selectCompany()`).
 * Kreator jest teraz natywnym `Wizard`-em w `FisheryResource\Pages\CreateFishery`
 * i zakłada firmę u siebie, więc tamta gałąź zniknęła w całości (ADR-006).
 */
class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getFormActions(): array
    {
        return [
            parent::getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another company')),
            parent::getCancelFormAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('Create company');
    }
}
