<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLongTermPermit extends EditRecord
{
    protected static string $resource = LongTermPermitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
