<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Helpers\Helper;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFisheries extends ListRecords
{
    protected static string $resource = FisheryResource::class;

    protected function getHeaderActions(): array
    {
        if (Helper::isOwnerPanel()) {
            $url = 'filament.owner.resources.companies.create';
            $urlParameters = ['wizard' => 1];

            return [
                Action::make('create')
                    ->label(__('Create fishery'))
                    ->url(route($url, $urlParameters))
                    ->color('primary'),
            ];
        }

        return [CreateAction::make()];
    }
}
