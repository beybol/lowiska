<?php

namespace App\Filament\Resources\LongTermPermitResource\Pages;

use App\Filament\Resources\LongTermPermitResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLongTermPermits extends ListRecords
{
    protected static string $resource = LongTermPermitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Long term permits');
    }
}
