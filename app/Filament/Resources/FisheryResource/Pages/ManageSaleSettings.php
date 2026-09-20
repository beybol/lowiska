<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\SaleMode;
use App\Filament\Resources\FisheryResource;
use App\Helpers\Helper;
use App\Models\Fishery;
use App\Rules\SalePeriodsDoNotOverlap;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Ekran „Sprzedaż i sezony": tryb sprzedaży, godziny doby i okresy sprzedaży
 * w jednym formularzu, z jednym „Zapisz".
 *
 * ⚠️ To jest ZAKŁADKA KONFIGURACYJNA, nie lista rekordów podrzędnych — dlatego
 * jest stroną ustawień, a nie RelationManagerem (ADR-006, aktualizacja z zadania
 * 015). Granica: lista rekordów mających własne strony → RelationManager;
 * konfiguracja łowiska zapisywana jednym przyciskiem → strona taka jak ta.
 *
 * ⚠️ Nie mylić z `ManageFishery` — tamta strona jest hubem i celowo NIE zawiera
 * formularza edycji łowiska (ADR-006). Ta jest formularzem i nie jest hubem.
 *
 * Okresy sprzedaży renderuje `Repeater`, bo `SalePeriod` świadomie nie ma własnego
 * zasobu: ma dwie daty i nazwę, więc trzy strony CRUD byłyby kosztem bez pokrycia.
 * Konsekwencja dla autoryzacji: model nie ma własnej polityki, dostępu pilnuje
 * `FisheryPolicy` przez `EditRecord::authorizeAccess()` — patrz
 * `docs/conventions/autoryzacja.md` §5.
 */
class ManageSaleSettings extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function getNavigationLabel(): string
    {
        return __('Sale and seasons');
    }

    public function getTitle(): string
    {
        return __('Sale and seasons').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Sale and seasons');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return Helper::fisheryBreadcrumbs($this->fishery()->id, __('Sale and seasons'));
    }

    /**
     * Zapis wraca do huba, tak samo jak strony zasobów podrzędnych — operator
     * kończy konfigurację tam, skąd ją zaczął (zadanie 012, ADR-006).
     */
    public function getRedirectUrl(): string
    {
        return FisheryResource::getUrl('manage', ['record' => $this->fishery()]);
    }

    public function form(Schema $schema): Schema
    {
        // ⚠️ `EditRecord::defaultForm()` narzuca `columns(2)`, o ile schemat sam nie
        // zadeklaruje kolumn. Bez tego obie sekcje stoją obok siebie, a repeater okresów
        // ze swoimi trzema kolumnami dostaje połowę szerokości i jest ściśnięty.
        return $schema->columns(1)->components([
            Section::make(__('How you sell time at a position'))
                ->description(__('Changing the hour applies from today and does not affect reservations already sold.'))
                ->schema([
                    Select::make('sale_mode')
                        ->label(__('Sale mode'))
                        ->options(SaleMode::options())
                        ->default(SaleMode::DailyPeriod->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                    TimePicker::make('day_start_time')
                        ->label(__('The fishing day starts at'))
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('day_end_time')
                        ->label(__('The fishing day ends at'))
                        ->helperText(__('On the following day — a fishing day always crosses midnight.'))
                        ->seconds(false)
                        ->required(),
                    Select::make('timezone')
                        ->label(__('Time zone'))
                        ->options(array_combine(
                            \DateTimeZone::listIdentifiers(),
                            \DateTimeZone::listIdentifiers(),
                        ))
                        ->searchable()
                        ->default('Europe/Warsaw')
                        ->required(),
                ])
                ->columns(2),
            Section::make(__('Sale periods'))
                ->description(__('Outside these periods the fishery is closed and nothing can be bought — even when a position is free.'))
                ->schema([
                    Repeater::make('salePeriods')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            DatePicker::make('starts_on')
                                ->label(__('From'))
                                ->required(),
                            DatePicker::make('ends_on')
                                ->label(__('To'))
                                ->required(),
                            TextInput::make('name')
                                ->label(__('Own name'))
                                ->maxLength(255),
                        ])
                        ->columns(3)
                        ->reorderable(false)
                        ->addActionLabel(__('Add period'))
                        // ⚠️ Reguła siedzi na całym repeaterze, nie na pojedynczym
                        // polu: nienachodzenie jest własnością ZBIORU okresów,
                        // więc walidacja pojedynczego wiersza nigdy by go nie
                        // zobaczyła.
                        ->rules([new SalePeriodsDoNotOverlap]),
                ]),
        ]);
    }

    /**
     * `getRecord()` deklaruje `Model`, więc bez tego zawężenia każde sięgnięcie po
     * pole łowiska jest dla analizy statycznej dostępem do nieznanej właściwości
     * (ten sam zabieg co w `ManageFishery`, zadanie 012).
     */
    private function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }
}
