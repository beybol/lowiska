<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Helpers\Helper;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFisheries extends ListRecords
{
    protected static string $resource = FisheryResource::class;

    /**
     * ⚠️ Zadanie 012: w panelu właściciela ten przycisk prowadził własnym URL-em
     * do `companies.create?wizard=1`, bo kreator zaczynał się od osobnej strony
     * firmy. Kreator jest teraz `Wizard`-em na stronie tworzenia ŁOWISKA, czyli
     * dokładnie tam, gdzie i tak kieruje standardowa akcja — własna trasa
     * przestała być potrzebna. Zostaje wyłącznie etykieta, żeby nie zmieniać
     * napisu widocznego dla właściciela.
     */
    protected function getHeaderActions(): array
    {
        if (Helper::isOwnerPanel()) {
            return [
                CreateAction::make()->label(__('Create fishery')),
            ];
        }

        return [CreateAction::make()];
    }
}
