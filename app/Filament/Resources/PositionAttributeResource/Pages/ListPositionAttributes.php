<?php

namespace App\Filament\Resources\PositionAttributeResource\Pages;

use App\Filament\Resources\PositionAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * ⚠️ `ListRecords`, a nie `ManageRecords` jak w prostych słownikach (udogodnienia,
 * ryby, metody łowienia). Formularz cechy niesie repeater opcji, który w modalu
 * `ManageRecords` jest ściśnięty — i to właśnie przez `ManageRecords` strona
 * `create` tego zasobu była zarejestrowana, ale nieosiągalna z żadnego odnośnika.
 */
class ListPositionAttributes extends ListRecords
{
    protected static string $resource = PositionAttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
