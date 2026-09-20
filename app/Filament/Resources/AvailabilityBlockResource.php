<?php

namespace App\Filament\Resources;

use App\Enums\BlockEffect;
use App\Enums\PositionAttributeType;
use App\Enums\SelectionKind;
use App\Filament\Resources\AvailabilityBlockResource\Pages\CreateAvailabilityBlock;
use App\Filament\Resources\AvailabilityBlockResource\Pages\EditAvailabilityBlock;
use App\Filament\Resources\AvailabilityBlockResource\Pages\ListAvailabilityBlocks;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionGroup;
use App\Rules\AvailabilityBlockEffectMatchesAttribute;
use App\Rules\PositionsBelongToFishery;
use App\Services\AvailabilityBlockSelectionResolver;
use App\Services\FisheryAccess;
use App\Services\SharedFormComponents;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Blokady i ograniczenia — jeden wpis ze skutkiem, zasób współdzielony przez oba panele.
 *
 * ⚠️ Formularz PROWADZI przez wybór zbioru: sposób wyboru → kryterium → lista objętych
 * stanowisk z licznikiem i przyciskiem przeliczenia. Kryterium podpowiada, lista
 * przesądza — po przeliczeniu da się ją poprawić ręcznie, a zapisany zbiór jest
 * zmaterializowany i nie zmienia się razem z kryterium.
 *
 * ⚠️ Zasób nie liczy dostępności. Odpowiada na nią wyłącznie `PositionAvailability`
 * (ADR-012); tutaj są tylko dane wejściowe.
 */
class AvailabilityBlockResource extends Resource
{
    protected static ?string $model = AvailabilityBlock::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            // ⚠️ Jedna kolumna na najwyższym poziomie, choć domyślny formularz zasobu
            // ma dwie. Sekcje są tu MODUŁAMI (skutek + okres, wybór zbioru), a przy
            // dwóch kolumnach lądowały obok siebie z polem łowiska jako sąsiadem —
            // lista stanowisk w trzech kolumnach nie miała wtedy szerokości. Kolumny
            // rozdaje każda sekcja u siebie.
            ->columns(1)
            ->components([
                ...SharedFormComponents::getFisheryFields(),
                Section::make(__('Effect and period'))
                    ->schema([
                        Select::make('effect')
                            ->label(__('Effect'))
                            // ⚠️ Skutki zależne od cech znikają, gdy słownik nie ma ani
                            // jednej cechy tak/nie — inaczej operator wybiera opcję,
                            // której `AvailabilityBlockEffectMatchesAttribute` nie pozwoli
                            // zapisać, i nie dowiaduje się dlaczego.
                            ->options(fn (?AvailabilityBlock $record): array => self::effectOptions($record))
                            ->helperText(fn (?AvailabilityBlock $record): ?string => self::flagAttributesMissing($record)
                                ? __('Attribute suspension becomes available once the administrator adds a yes/no attribute to the shared dictionary.')
                                : null)
                            ->default(BlockEffect::SaleBlocked->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required(),
                        Select::make('position_attribute_id')
                            ->label(__('Suspended attribute'))
                            // Wyłącznie cechy tak/nie — zawiesza się to, co stanowisko MA
                            // albo czego NIE MA (zadanie 016, „Rozstrzygnięcia").
                            ->options(fn (): array => self::flagAttributeOptions())
                            ->visible(fn (Get $get): bool => $get('effect') === BlockEffect::AttributeSuspended->value)
                            ->rules([
                                fn (Get $get): AvailabilityBlockEffectMatchesAttribute => new AvailabilityBlockEffectMatchesAttribute(
                                    BlockEffect::tryFrom((string) $get('effect')),
                                ),
                            ]),
                        // ⚠️ Obie daty w jednym `Grid`, nie luzem w siatce sekcji — inaczej
                        // „Od" dopełnia wiersz skutku, a „Do" zostaje samo w następnym.
                        // Układ, nie reguła: pola zachowują się dokładnie jak wcześniej.
                        Grid::make(2)
                            ->schema([
                                DatePicker::make('starts_on')
                                    ->label(__('From'))
                                    ->required(),
                                DatePicker::make('ends_on')
                                    ->label(__('To'))
                                    ->helperText(__('Leave empty for an entry valid until revoked.'))
                                    ->afterOrEqual('starts_on'),
                            ])
                            ->columnSpanFull(),
                        Textarea::make('reason')
                            ->label(__('Reason'))
                            ->rows(3)
                            ->required()
                            ->columnSpanFull(),
                        Toggle::make('reason_visible')
                            ->label(__('Reason visible to anglers'))
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make(__('Covered positions'))
                    ->description(__('The criterion suggests a set; the list below decides. Positions added later are not covered.'))
                    ->schema([
                        Select::make('selection_kind')
                            ->label(__('How to choose'))
                            // ⚠️ Ta sama reguła co przy skutku: kryterium „stanowiska z cechą"
                            // przy pustym słowniku daje pusty zbiór, którego
                            // `PositionsBelongToFishery` i tak nie przepuści.
                            ->options(fn (?AvailabilityBlock $record): array => self::selectionKindOptions($record))
                            ->default(SelectionKind::Fishery->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required(),
                        Select::make('position_group_id')
                            ->label(__('Position group'))
                            ->options(fn (Get $get): array => self::groupOptions($get('fishery_id')))
                            ->visible(fn (Get $get): bool => $get('selection_kind') === SelectionKind::Group->value)
                            // ⚠️ Pole NIE jest kolumną, ale musi dojechać do `mutateFormDataBefore*` —
                            // z niego powstaje `selection_label`. Klucz zdejmuje `withSelectionLabel()`.
                            ->live(),
                        Select::make('selection_attribute_id')
                            ->label(__('Attribute'))
                            ->options(fn (): array => self::flagAttributeOptions())
                            ->visible(fn (Get $get): bool => $get('selection_kind') === SelectionKind::Attribute->value)
                            // ⚠️ Pole NIE jest kolumną, ale musi dojechać do `mutateFormDataBefore*` —
                            // z niego powstaje `selection_label`. Klucz zdejmuje `withSelectionLabel()`.
                            ->live(),
                        Actions::make([
                            Action::make('recalculate')
                                ->label(__('Recalculate the list'))
                                ->icon('heroicon-m-arrow-path')
                                ->visible(fn (Get $get): bool => $get('selection_kind') !== SelectionKind::Manual->value)
                                ->action(function (Get $get, Set $set): void {
                                    $set('positions', self::resolveSelection($get)->all());
                                }),
                        ])
                            // ⚠️ Klucz jest OBOWIĄZKOWY, nie kosmetyką. `Actions` nie ma
                            // ścieżki stanu, więc bez `key()` komponent nie ma klucza,
                            // a Livewire nie potrafi odnaleźć akcji na powrotnym żądaniu:
                            // klik kończył się `ActionNotResolvableException`
                            // („Action [recalculate] not found in schema at []").
                            ->key('recalculateActions')
                            ->columnSpanFull(),
                        CheckboxList::make('positions')
                            ->label(__('Positions'))
                            ->relationship('positions', 'name')
                            // ⚠️ Lista liczy się z `fishery_id`, czyli z pola `Hidden` — danych od
                            // klienta. Bez zawężenia podmiana stanu wyświetliłaby nazwy stanowisk
                            // CUDZEGO łowiska (`autoryzacja.md` §4).
                            ->options(fn (Get $get): array => self::positionOptions($get('fishery_id')))
                            ->columns(3)
                            ->bulkToggleable()
                            ->live()
                            ->helperText(fn (Get $get): string => trans_choice(
                                ':count position covered|:count positions covered',
                                count((array) $get('positions')),
                                ['count' => count((array) $get('positions'))],
                            ))
                            // ⚠️ Zbiór niepusty i w całości z łowiska wpisu — reguła, nie samo
                            // zawężenie opcji, bo identyfikatory przychodzą od klienta.
                            ->rules([
                                fn (Get $get): PositionsBelongToFishery => new PositionsBelongToFishery($get('fishery_id')),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effect')
                    ->label(__('Effect'))
                    ->badge()
                    ->formatStateUsing(fn (BlockEffect $state): string => $state->label())
                    ->color(fn (BlockEffect $state): string => $state === BlockEffect::SaleBlocked ? 'danger' : 'warning'),
                TextColumn::make('starts_on')
                    ->label(__('From'))
                    ->date(),
                TextColumn::make('ends_on')
                    ->label(__('To'))
                    ->date()
                    ->placeholder(__('until revoked')),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->limit(30),
                TextColumn::make('positions_count')
                    ->label(__('Positions'))
                    ->counts('positions'),
                TextColumn::make('selection_label')
                    ->label(__('How chosen'))
                    ->placeholder('—'),
            ])
            ->defaultSort('starts_on', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAvailabilityBlocks::route('/'),
            'create' => CreateAvailabilityBlock::route('/create'),
            'edit' => EditAvailabilityBlock::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Availability blocks');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Availability blocks');
    }

    public static function getModelLabel(): string
    {
        return __('availability block');
    }

    /**
     * ⚠️ Jedyna warstwa działająca na odczycie POJEDYNCZEGO rekordu — bez niej da się
     * wejść na cudzy wpis wprost z adresu (`autoryzacja.md` §4).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        FisheryAccess::scopeToOwnedFisheries($query);

        return $query->with('fishery');
    }

    /**
     * Czytelny zapis kryterium do `selection_label` — po zapisie zbiór jest listą,
     * a etykieta tłumaczy operatorowi, skąd się wzięła.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withSelectionLabel(array $data): array
    {
        $kind = SelectionKind::tryFrom((string) ($data['selection_kind'] ?? ''));

        // ⚠️ Identyfikator grupy przychodzi z pola `Select`, czyli od klienta. Opcje są
        // zawężone, ale sama wartość nie — bez `where('fishery_id')` podmiana stanu
        // zapisywała do etykiety NAZWĘ CUDZEJ GRUPY, którą panel potem renderuje
        // (`autoryzacja.md` §4: nazwa cudzego rekordu też jest danymi).
        // Cechy takiego zawężenia nie mają i mieć nie mogą — słownik cech jest wspólny
        // dla całego portalu (zadanie 014).
        $data['selection_label'] = match ($kind) {
            SelectionKind::Group => PositionGroup::query()
                ->whereKey($data['position_group_id'] ?? null)
                ->where('fishery_id', $data['fishery_id'] ?? null)
                ->value('name'),
            SelectionKind::Attribute => PositionAttribute::find($data['selection_attribute_id'] ?? null)?->name,
            default => null,
        };

        // Pola kryterium nie są kolumnami — zostawione w tablicy trafiłyby do `fill()`.
        unset($data['position_group_id'], $data['selection_attribute_id']);

        // ⚠️ Blokada sprzedaży nie wskazuje cechy. Pole jest wtedy UKRYTE, więc przy
        // zmianie skutku z zawieszenia na blokadę reguła `AvailabilityBlockEffectMatchesAttribute`
        // nie ma czego sprawdzić i stara wartość zostawała w kolumnie. Sprzedawalność
        // liczy się po `effect`, więc to były dane-śmieci, nie błędny werdykt — ale
        // kolumna ma mówić prawdę o wpisie.
        if (BlockEffect::tryFrom((string) ($data['effect'] ?? '')) !== BlockEffect::AttributeSuspended) {
            $data['position_attribute_id'] = null;
        }

        return $data;
    }

    /**
     * @return Collection<int, int>
     */
    private static function resolveSelection(Get $get): Collection
    {
        $fisheryId = $get('fishery_id');

        if (! is_numeric($fisheryId)) {
            return collect();
        }

        // ⚠️ `scopeToOwnedFisheries()` zawęża zasoby PODRZĘDNE (po `fishery_id`)
        // i użyte na `Fishery` wywalało zapytanie z „Unknown column 'fishery_id'".
        // Bramką dla samego łowiska jest `findFishery()` — domyślnie zawężone
        // do łowisk bieżącego użytkownika poza panelem admina (`autoryzacja.md` §4).
        $fishery = FisheryAccess::findFishery($fisheryId);

        if (! $fishery instanceof Fishery) {
            return collect();
        }

        $kind = SelectionKind::tryFrom((string) $get('selection_kind')) ?? SelectionKind::Manual;

        $criterion = match ($kind) {
            SelectionKind::Group => $get('position_group_id'),
            SelectionKind::Attribute => $get('selection_attribute_id'),
            default => null,
        };

        return app(AvailabilityBlockSelectionResolver::class)->resolve($fishery, $kind, $criterion);
    }

    /**
     * Cechy, które da się zawiesić — WYŁĄCZNIE typu flaga. Jedno źródło dla obu list
     * cech w formularzu i dla bramki na opcjach wyżej (`dostepnosc.md` §3: zawieszenie
     * liczby albo wyboru z listy byłoby NADPISANIEM, czyli innym pojęciem).
     *
     * @return array<int, string>
     */
    private static function flagAttributeOptions(): array
    {
        return PositionAttribute::query()
            ->where('type', PositionAttributeType::Flag->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Czy opcje zależne od cech są dziś ślepą uliczką.
     *
     * ⚠️ Pyta o `$record`, a NIE o stan własnego pola przez `Get` — komponent
     * odczytujący klucz stanu o swojej nazwie wpada w rekursję bez dna
     * (`panel-admina.md` §2). Zapisany wpis zachowuje swoją opcję nawet po
     * opróżnieniu słownika, żeby edycja nie gubiła wartości po cichu.
     */
    private static function flagAttributesMissing(?AvailabilityBlock $record): bool
    {
        return $record?->effect !== BlockEffect::AttributeSuspended
            && $record?->selection_kind !== SelectionKind::Attribute
            && self::flagAttributeOptions() === [];
    }

    /**
     * @return array<string, string>
     */
    private static function effectOptions(?AvailabilityBlock $record): array
    {
        $options = BlockEffect::options();

        if (self::flagAttributesMissing($record)) {
            unset($options[BlockEffect::AttributeSuspended->value]);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function selectionKindOptions(?AvailabilityBlock $record): array
    {
        $options = SelectionKind::options();

        if (self::flagAttributesMissing($record)) {
            unset($options[SelectionKind::Attribute->value]);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private static function positionOptions(mixed $fisheryId): array
    {
        if (! is_numeric($fisheryId)) {
            return [];
        }

        $query = Position::query()->where('fishery_id', (int) $fisheryId)->orderBy('name');
        FisheryAccess::scopeToOwnedFisheries($query);

        return $query->pluck('name', 'id')->toArray();
    }

    /**
     * @return array<int, string>
     */
    private static function groupOptions(mixed $fisheryId): array
    {
        if (! is_numeric($fisheryId)) {
            return [];
        }

        $query = PositionGroup::query()->where('fishery_id', (int) $fisheryId)->orderBy('name');
        FisheryAccess::scopeToOwnedFisheries($query);

        return $query->pluck('name', 'id')->toArray();
    }
}
