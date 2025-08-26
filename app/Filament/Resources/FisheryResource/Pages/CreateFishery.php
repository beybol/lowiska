<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use App\Helpers\Helper;

class CreateFishery extends CreateRecord
{
    protected static string $resource = FisheryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (Helper::isOwnerPanel()) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }
}
