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
 * Dane łowiska — pierwsza pozycja sub-nawigacji rekordu.
 *
 * ⚠️ Strona celowo **nie** zawiera formularza edycji łowiska: to podgląd (infolist)
 * z akcją nagłówka „Edytuj" prowadzącą do osobnego formularza. Ta granica jest istotą
 * decyzji z ADR-006 i obowiązuje dalej — nie zamieniaj `ViewRecord` na `EditRecord`.
 *
 * ⚠️ Strona nie ma już zakładek. Od zadania 016 listy zasobów podrzędnych i ekrany
 * konfiguracyjne są osobnymi stronami w `FisheryResource::getRecordSubNavigation()`
 * (ADR-006, aktualizacja z zadania 016).
 */
class ManageFishery extends ViewRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    public static function getNavigationLabel(): string
    {
        return __('Fishery data');
    }

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
     * Strona jest podglądem, więc edycja musi mieć własne wejście — inaczej nie da się
     * przejść do formularza łowiska (zadanie 012).
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
