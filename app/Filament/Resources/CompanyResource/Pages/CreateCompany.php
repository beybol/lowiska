<?php

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CountryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Actions\Action;

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
            Action::make('createAndAddFishery')
                ->label(__('Create and add fishery'))
                ->color('success')
                ->action(function () {
                    $this->create();
                    
                    return redirect()->to(CountryResource::getUrl('create'));
                }),
            $this->getCreateFormAction(),
            $this->getCancelFormAction(),
        ];
    }
}
