<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Hub zarządzania łowiskiem: zakładka z danymi łowiska + po jednej zakładce na
 * każdy zasób podrzędny (pozwolenia, usługi dodatkowe, stanowiska).
 *
 * ⚠️ Strona celowo **nie** zawiera formularza edycji łowiska — pierwsza zakładka
 * to podgląd (infolist), nie formularz. Ta granica jest istotą decyzji z ADR-006;
 * nie zamieniaj `ViewRecord` na `EditRecord`, bo hub przestanie być hubem.
 *
 * Listy zasobów podrzędnych renderują RelationManagery, więc widać je od razu po
 * wejściu w zakładkę — wcześniej były schowane za przyciskiem „Lista" (zadanie 012).
 * Zakładki i ich układ daje wbudowany mechanizm Filamenta
 * (`hasCombinedRelationManagerTabsWithContent()`), nie własny Alpine ani `Tabs`.
 */
class ManageFishery extends ViewRecord
{
    protected static string $resource = FisheryResource::class;

    public function getTitle(): string
    {
        return __('Manage fishery').' '.$this->fishery()->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            FisheryResource::getUrl('index') => __('Fisheries'),
            $this->fishery()->name,
        ];
    }

    /**
     * `getRecord()` deklaruje `Model`, więc każde sięgnięcie po pole łowiska było
     * dla analizy statycznej dostępem do nieznanej właściwości. Zawężenie w jednym
     * miejscu zdjęło dwa wpisy z baseline'u PHPStana (zadanie 012).
     */
    private function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }

    public function getBreadcrumb(): string
    {
        return __('Manage');
    }

    /**
     * Zakładka z danymi łowiska ma być PIERWSZA, żeby wejście w zarządzanie nie
     * wrzucało od razu w jedną z trzech list.
     */
    /**
     * Zakładka z danymi łowiska jest podglądem, więc edycja musi mieć własne wejście —
     * inaczej z huba nie da się przejść do formularza łowiska (zadanie 012).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('Edit'))
                ->icon('heroicon-m-pencil-square')
                ->url(fn (): string => FisheryResource::getUrl('edit', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Fishery data');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Fishery data'))->schema([
                TextEntry::make('name')->label(__('Fishery name')),
                TextEntry::make('company.name')->label(__('Company')),
                TextEntry::make('area')->label(__('Area (in hectares)')),
                TextEntry::make('positions_count')->label(__('Positions count')),
            ])->columns(2),
            Section::make(__('Fishery address'))->schema([
                TextEntry::make('street')->label(__('Street')),
                TextEntry::make('building_number')->label(__('Building number')),
                TextEntry::make('zip_code')->label(__('Postal code')),
                TextEntry::make('town')->label(__('Town')),
                TextEntry::make('state.name')->label(__('State')),
            ])->columns(2),
        ]);
    }
}
