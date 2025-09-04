<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use Filament\Actions\CreateAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Facades\Filament;
use App\Helpers\Helper;

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
