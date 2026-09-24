<?php

namespace App\Filament\Resources;

use App\Enums\PositionAttributeType;
use App\Enums\PositionStatus;
use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Models\AdditionalService;
use App\Models\LongTermPermit;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionGroup;
use App\Rules\RecordsBelongToFishery;
use App\Services\AdditionalServiceSync;
use App\Services\FisheryAccess;
use App\Services\PositionAttributeWriter;
use App\Services\PositionServices;
use App\Services\SharedFormComponents;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...SharedFormComponents::getFisheryFields(),
                // ⚠️ Stan WŁASNY stanowiska, nie dostępność w terminie. Ta druga zależy
                // od czasu i składa ją zadanie 016 z blokad i okresów sprzedaży.
                Select::make('status')
                    ->label(__('Status'))
                    ->options(PositionStatus::options())
                    ->default(PositionStatus::Available->value)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label(__('Position name')),
                TextInput::make('max_anglers')
                    ->label(__('Maximum anglers'))
                    ->helperText(__('How many people may fish at this position.'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(255)
                    ->required(),
                TextInput::make('max_people')
                    ->label(__('Maximum people'))
                    ->helperText(__('Including people who do not fish.'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(255)
                    ->rules([
                        // ⚠️ Reguła porównawcza zamiast `gte:max_anglers` — pole bywa puste,
                        // a wtedy porównanie ma się w ogóle nie odbyć.
                        fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                            $maxAnglers = $get('max_anglers');

                            if (blank($value) || blank($maxAnglers)) {
                                return;
                            }

                            if ((int) $value < (int) $maxAnglers) {
                                $fail(__('The maximum number of people can not be lower than the maximum number of anglers.'));
                            }
                        },
                    ]),
                Select::make('groups')
                    ->label(__('Position groups'))
                    ->relationship('groups', 'name')
                    ->multiple()
                    ->preload()
                    // ⚠️ Lista grup liczy się z `fishery_id`, czyli z pola `Hidden` —
                    // danych od klienta. Bez zawężenia podmiana stanu wyświetliłaby
                    // nazwy grup CUDZEGO łowiska (`autoryzacja.md` §4).
                    ->options(function (Get $get): array {
                        $fisheryId = $get('fishery_id');

                        if (! is_numeric($fisheryId)) {
                            return [];
                        }

                        $query = PositionGroup::query()->where('fishery_id', (int) $fisheryId);
                        FisheryAccess::scopeToOwnedFisheries($query);

                        return $query->pluck('name', 'id')->toArray();
                    })
                    // ⚠️ Reguła, NIE samo zawężenie opcji — bez niej dało się wepchnąć
                    // własne stanowisko do CUDZEJ grupy, a wtedy jej właściciel obejmuje
                    // je swoimi akcjami zbiorczymi (security-review, 2026-09-20).
                    ->rules([
                        fn (Get $get): RecordsBelongToFishery => new RecordsBelongToFishery(
                            PositionGroup::class,
                            $get('fishery_id'),
                        ),
                    ]),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions()),
                CheckboxList::make('long_term_permit_id')
                    ->relationship('longTermPermits', 'description')
                    ->label(__('Long term permits'))
                    ->options(function (callable $get) {
                        $fisheryId = $get('fishery_id');

                        // ⚠️ `is_numeric`, nie samo `if (! $fisheryId)` — wartość jest
                        // klienckim stanem pola `Hidden`, a `forFishery()` typuje `int`,
                        // więc `"abc"` dawało TypeError (500) zamiast pustej listy.
                        if (! is_numeric($fisheryId)) {
                            return [];
                        }

                        // ⚠️ `fishery_id` pochodzi z pola `Hidden`, czyli od klienta —
                        // bez zawężenia podmiana stanu wyrenderowała opisy pozwoleń
                        // CUDZEGO łowiska. Zapis był bezpieczny, odczyt nie.
                        $query = LongTermPermit::query()
                            ->forFishery($fisheryId)
                            ->isActive();
                        FisheryAccess::scopeToOwnedFisheries($query);

                        return $query
                            ->get()
                            ->mapWithKeys(function ($item) {
                                return [$item->id => strip_tags($item->description)];
                            })
                            ->toArray();
                    })
                    ->reactive()
                    ->columnSpan('full')
                    ->visible(function (callable $get) {
                        $fisheryId = $get('fishery_id');

                        if (! is_numeric($fisheryId)) {
                            return false;
                        }

                        $query = LongTermPermit::query()
                            ->forFishery($fisheryId)
                            ->isActive();
                        FisheryAccess::scopeToOwnedFisheries($query);

                        return $query->exists();
                    })
                    // ⚠️ Reguła, NIE samo zawężenie opcji — identyfikatory pozwoleń
                    // przychodzą od klienta jak każdy inny stan komponentu.
                    ->rules([
                        fn (callable $get): RecordsBelongToFishery => new RecordsBelongToFishery(
                            LongTermPermit::class,
                            $get('fishery_id'),
                        ),
                    ]),
                Repeater::make('additionalServices')
                    ->statePath('additionalServices')
                    ->schema([
                        Select::make('additional_service_id')
                            ->label(__('Additional service'))
                            ->options(function (Get $get) {
                                $fisheryId = $get('../../fishery_id') ?? request()->get('fishery');

                                if (! is_numeric($fisheryId)) {
                                    return [];
                                }

                                // ⚠️ Jak wyżej — bez zawężenia lista pokazywała nazwy
                                // usług cudzego łowiska.
                                // ⚠️ Wyłącznie usługi „wybrane stanowiska" — ogólnołowiskowej nie da się
                                // przypiąć (zadanie 020). Zapis pilnuje tego sam, niezależnie od listy.
                                $query = AdditionalService::forFishery($fisheryId)
                                    ->isActive()
                                    ->pinnable();
                                FisheryAccess::scopeToOwnedFisheries($query);

                                return $query->pluck('name', 'id')->toArray();
                            })
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(true)
                            ->required(),
                        Checkbox::make('is_required')
                            ->label(__('Is required')),
                    ])
                    ->label(__('Additional services'))
                    // ⚠️ Bez tego Filament renderuje JEDNĄ pustą pozycję (domyślne
                    // `defaultItems(1)`), a że wybór usługi jest `required()`,
                    // stanowisko bez usług dodatkowych nie dawało się zapisać,
                    // dopóki użytkownik ręcznie nie usunął pustego wiersza.
                    // Usługi dodaje się przyciskiem niżej (zadanie 012).
                    ->defaultItems(0)
                    ->addActionLabel(__('Add additional service')),
                Section::make(__('Position attributes'))
                    ->description(__('Attributes come from the shared dictionary managed by the administrator.'))
                    ->schema(static::attributeComponents())
                    ->visible(fn (): bool => PositionAttribute::query()->exists()),
            ]);
    }

    /**
     * Komponenty cech GENEROWANE ZE SŁOWNIKA — dodanie wpisu w panelu administratora
     * udostępnia cechę we wszystkich łowiskach bez zmiany kodu i bez migracji.
     *
     * ⚠️ Stan siedzi pod kluczem `position_attributes`, nie `attributes`: to drugie
     * koliduje z magiczną właściwością Eloquenta i zapis nadpisywałby model.
     *
     * ⚠️ Pole puste to TRZECI STAN („nikt się nie wypowiedział"), nie „nie" — dlatego
     * flaga jest `Select` z pustą opcją, a nie `Toggle`, który zawsze niesie fałsz.
     *
     * @return array<int, mixed>
     */
    public static function attributeComponents(): array
    {
        return PositionAttribute::query()
            ->with('options')
            ->orderBy('name')
            ->get()
            ->map(function (PositionAttribute $attribute) {
                $statePath = 'position_attributes.'.$attribute->id;

                return match ($attribute->type) {
                    PositionAttributeType::Flag => Select::make($statePath)
                        ->label($attribute->name)
                        ->options([1 => __('Yes'), 0 => __('No')])
                        ->placeholder(__('Not specified')),
                    PositionAttributeType::Number => TextInput::make($statePath)
                        ->label($attribute->name)
                        ->numeric()
                        ->suffix($attribute->unit)
                        ->placeholder(__('Not specified')),
                    PositionAttributeType::Choice => Select::make($statePath)
                        ->label($attribute->name)
                        ->options($attribute->options->pluck('name', 'id')->toArray())
                        ->placeholder(__('Not specified')),
                };
            })
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (PositionStatus $state): string => $state->label())
                    ->color(fn (PositionStatus $state): string => $state === PositionStatus::Available ? 'success' : 'gray'),
                TextColumn::make('max_anglers')
                    ->label(__('Maximum anglers')),
                TextColumn::make('name')
                    ->label(__('Position name'))
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->formatStateUsing(function (string $state) {
                        return strip_tags($state);
                    })
                    ->limit(20)
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::setAttributeBulkAction(),
                    static::pinServiceBulkAction(),
                    static::unpinServiceBulkAction(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Akcja zbiorcza — ustawienie JEDNEJ cechy na zaznaczonych stanowiskach.
     *
     * ⚠️ To jest PRYMITYW, który zastępuje dziedziczenie po grupie: zapisuje wartość
     * wprost na każdym stanowisku, więc po wykonaniu każde niesie własną i nic nie jest
     * rozwiązywane przy odczycie. Akcja wywołana z poziomu grupy jest skrótem do tego
     * samego kodu, z zaznaczeniem wypełnionym stanowiskami grupy.
     *
     * ⚠️ Nie ma wariantu „ustaw wszystkim" bez wskazania wartości — to byłoby
     * dziedziczenie tylnymi drzwiami, tylko niewidoczne (zadanie 014).
     */
    public static function setAttributeBulkAction(): BulkAction
    {
        return BulkAction::make('setPositionAttribute')
            ->label(__('Set an attribute'))
            ->icon('heroicon-m-tag')
            ->schema(static::attributeAssignmentSchema())
            ->requiresConfirmation()
            // Liczba objętych stanowisk PRZED zapisem — operator ma wiedzieć, w co klika.
            ->modalDescription(fn (Collection $records): string => trans_choice(
                'The attribute will be set on :count position|The attribute will be set on :count positions',
                $records->count(),
                ['count' => $records->count()],
            ))
            ->action(fn (Collection $records, array $data) => static::applyAttributeAssignment($records, $data))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Pola wyboru cechy i wartości — WSPÓLNE dla akcji zbiorczej na tabeli stanowisk
     * i dla jej skrótu z poziomu grupy. Jeden schemat, dwa wejścia.
     *
     * @return array<int, mixed>
     */
    public static function attributeAssignmentSchema(): array
    {
        return [
            Select::make('position_attribute_id')
                ->label(__('Attribute'))
                ->options(fn (): array => PositionAttribute::query()->orderBy('name')->pluck('name', 'id')->toArray())
                ->live()
                ->required(),
            // Trzy pola wartości, z których widoczne jest to pasujące do typu cechy —
            // ten sam podział co w kolumnach tabeli wartości (ADR-011).
            Select::make('value_flag')
                ->label(__('Value'))
                ->options([1 => __('Yes'), 0 => __('No')])
                ->visible(fn (Get $get): bool => self::attributeTypeOf($get('position_attribute_id')) === PositionAttributeType::Flag)
                ->required(),
            TextInput::make('value_number')
                ->label(__('Value'))
                ->numeric()
                ->visible(fn (Get $get): bool => self::attributeTypeOf($get('position_attribute_id')) === PositionAttributeType::Number)
                ->required(),
            Select::make('position_attribute_option_id')
                ->label(__('Value'))
                ->options(fn (Get $get): array => PositionAttribute::find($get('position_attribute_id'))
                    ?->options->pluck('name', 'id')->toArray() ?? [])
                ->visible(fn (Get $get): bool => self::attributeTypeOf($get('position_attribute_id')) === PositionAttributeType::Choice)
                ->required(),
        ];
    }

    /**
     * Wykonanie przypisania — również wspólne dla obu wejść.
     *
     * @param  Collection<int, Position>  $positions
     * @param  array<string, mixed>  $data
     */
    public static function applyAttributeAssignment(Collection $positions, array $data): int
    {
        $attribute = PositionAttribute::find($data['position_attribute_id'] ?? null);

        if (! $attribute instanceof PositionAttribute) {
            return 0;
        }

        $rawValue = match ($attribute->type) {
            PositionAttributeType::Flag => $data['value_flag'] ?? null,
            PositionAttributeType::Number => $data['value_number'] ?? null,
            PositionAttributeType::Choice => $data['position_attribute_option_id'] ?? null,
        };

        try {
            $count = app(PositionAttributeWriter::class)->writeForMany($positions, $attribute, $rawValue);
        } catch (ValidationException $exception) {
            // Reguła ADR-011 odrzuciła wartość. Akcja zbiorcza nie ma formularza, do
            // którego dałoby się przypiąć błąd — stąd komunikat i ZERO zapisanych
            // stanowisk zamiast częściowego przypisania.
            Notification::make()
                ->danger()
                ->title(__('The attribute was not set'))
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            return 0;
        }

        Notification::make()
            ->success()
            ->title(trans_choice(
                'The attribute was set on :count position|The attribute was set on :count positions',
                $count,
                ['count' => $count],
            ))
            ->send();

        return $count;
    }

    /**
     * Akcja zbiorcza „Przypnij usługę" — przypięcie WPROST na każdym zaznaczonym stanowisku,
     * zamiast przypisania usługi do grupy (F5, O11: grupa niczego nie przekazuje stanowiskom).
     * Ten sam prymityw co „Ustaw cechę": zaznaczenie liczy się w chwili wykonania, a akcja
     * z poziomu grupy jest skrótem do tego samego kodu (zadanie 020).
     */
    public static function pinServiceBulkAction(): BulkAction
    {
        return BulkAction::make('pinAdditionalService')
            ->label(__('Pin a service'))
            ->icon('heroicon-m-link')
            ->schema(fn (mixed $livewire): array => static::serviceAssignmentSchema(self::fisheryIdOf($livewire), withRequired: true))
            ->requiresConfirmation()
            ->modalDescription(fn (Collection $records): string => trans_choice(
                'The service will be pinned to :count position|The service will be pinned to :count positions',
                $records->count(),
                ['count' => $records->count()],
            ))
            ->action(fn (Collection $records, array $data) => static::applyServicePin($records, $data))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Akcja zbiorcza „Odepnij usługę" — ten sam schemat wejść i ta sama bramka co przypięcie.
     */
    public static function unpinServiceBulkAction(): BulkAction
    {
        return BulkAction::make('unpinAdditionalService')
            ->label(__('Unpin a service'))
            ->icon('heroicon-m-link-slash')
            ->schema(fn (mixed $livewire): array => static::serviceAssignmentSchema(self::fisheryIdOf($livewire), withRequired: false))
            ->requiresConfirmation()
            ->modalDescription(fn (Collection $records): string => trans_choice(
                'The service will be unpinned from :count position|The service will be unpinned from :count positions',
                $records->count(),
                ['count' => $records->count()],
            ))
            ->action(fn (Collection $records, array $data) => static::applyServiceUnpin($records, $data))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Pola akcji przypięcia i odpięcia — WSPÓLNE dla tabeli stanowisk i poziomu grupy.
     *
     * ⚠️ Lista opcji zawiera wyłącznie usługi „wybrane stanowiska" łowiska bieżącego operatora,
     * ale nie jest walidacją — o zapisie rozstrzyga bramka `AdditionalServiceSync`.
     *
     * @return array<int, mixed>
     */
    public static function serviceAssignmentSchema(mixed $fisheryId, bool $withRequired): array
    {
        $fields = [
            Select::make('additional_service_id')
                ->label(__('Additional service'))
                ->options(function () use ($fisheryId): array {
                    if (! is_numeric($fisheryId)) {
                        return [];
                    }

                    $query = AdditionalService::forFishery((int) $fisheryId)->pinnable()->orderBy('name');
                    FisheryAccess::scopeToOwnedFisheries($query);

                    return $query->pluck('name', 'id')->toArray();
                })
                ->required(),
        ];

        if ($withRequired) {
            $fields[] = Checkbox::make('is_required')
                ->label(__('Is required'));
        }

        return $fields;
    }

    /**
     * @param  iterable<int, mixed>  $positions  rekordy tabeli albo stanowiska grupy
     * @param  array<string, mixed>  $data
     */
    public static function applyServicePin(iterable $positions, array $data): int
    {
        $pinned = AdditionalServiceSync::pin(
            $data['additional_service_id'] ?? null,
            self::positionsFrom($positions),
            (bool) ($data['is_required'] ?? false),
        );

        if ($pinned === []) {
            Notification::make()
                ->danger()
                ->title(__('The service was not pinned'))
                ->send();

            return 0;
        }

        $service = AdditionalService::query()->findOrFail($data['additional_service_id']);
        $lacking = PositionServices::countLackingRequiredAttributes($service, $pinned);

        $notification = Notification::make()
            ->title(trans_choice(
                'The service was pinned to :count position|The service was pinned to :count positions',
                count($pinned),
                ['count' => count($pinned)],
            ));

        // ⚠️ Ostrzeżenie bez ograniczeń czasowych — te pokazuje kalendarz. Tu chodzi o
        // stanowiska, na których usługa jest martwa zawsze: brakuje im wymaganej cechy.
        if ($lacking > 0) {
            $notification->warning()->body(trans_choice(
                'It is unavailable on :count of them, because a required attribute is missing.|It is unavailable on :count of them, because a required attribute is missing.',
                $lacking,
                ['count' => $lacking],
            ));
        } else {
            $notification->success();
        }

        $notification->send();

        return count($pinned);
    }

    /**
     * @param  iterable<int, mixed>  $positions  rekordy tabeli albo stanowiska grupy
     * @param  array<string, mixed>  $data
     */
    public static function applyServiceUnpin(iterable $positions, array $data): int
    {
        $removed = AdditionalServiceSync::unpin($data['additional_service_id'] ?? null, self::positionsFrom($positions));

        Notification::make()
            ->success()
            ->title(trans_choice(
                'The service was unpinned from :count position|The service was unpinned from :count positions',
                $removed,
                ['count' => $removed],
            ))
            ->send();

        return $removed;
    }

    /**
     * @param  iterable<int, mixed>  $records
     * @return array<int, Position>
     */
    private static function positionsFrom(iterable $records): array
    {
        return array_values(array_filter(
            is_array($records) ? $records : iterator_to_array($records, false),
            fn (mixed $record): bool => $record instanceof Position,
        ));
    }

    /**
     * Łowisko tabeli, na której wywołano akcję: strona sekcji łowiska niesie je jako rekord
     * nadrzędny, samodzielna lista — jako parametr `?fishery=` sprawdzony w `mount()`.
     */
    private static function fisheryIdOf(mixed $livewire): mixed
    {
        if ($livewire instanceof ManageRelatedRecords) {
            return $livewire->getOwnerRecord()->getKey();
        }

        return is_object($livewire) && property_exists($livewire, 'fisheryId') ? $livewire->fisheryId : null;
    }

    private static function attributeTypeOf(mixed $attributeId): ?PositionAttributeType
    {
        if (! is_numeric($attributeId)) {
            return null;
        }

        return PositionAttribute::find((int) $attributeId)?->type;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPositions::route('/'),
            'create' => CreatePosition::route('/create'),
            'edit' => EditPosition::route('/{record}/edit'),
        ];
    }

    public static function getEloquentFormData($record): array
    {
        $data = $record->toArray();
        unset($data['additional_services']);
        $data['additionalServices'] = $record->additionalServices
            ->map(fn ($service) => [
                'additional_service_id' => $service->id,
                'is_required' => $service->pivot->is_required,
            ])
            ->toArray();
        $data['additionalServices'] = array_values($data['additionalServices'] ?? []);

        // Cechy wracają do formularza pod tym samym kluczem, pod którym są zapisywane.
        // ⚠️ Brak wiersza NIE staje się tu fałszem — pole zostaje puste, bo „nikt się
        // nie wypowiedział" jest trzecim stanem, do którego trzeba móc wrócić.
        // ⚠️ Cecha jest kasowana MIĘKKO, a `position_attribute_values` kaskaduje tylko
        // przy twardym usunięciu — po usunięciu cechy ze słownika zostają wiersze bez
        // definicji. Bez tego filtra `->attribute->type` wywracało formularz edycji
        // każdego stanowiska, które miało tę cechę wypełnioną.
        // ⚠️ Cecha „tak/nie" wraca z bazy jako `bool`, ale stanem pola musi być 1 albo 0.
        // Filament dopasowuje stan do kluczy opcji po rzutowaniu na string, a
        // `(string) false` to PUSTY łańcuch — czyli dokładnie to, czym jest brak wyboru.
        // Pole pokazywało wtedy „nie określono" zamiast „nie", a ZAPIS takiego formularza
        // KASOWAŁ wiersz, bo pusty stan znaczy „nikt się nie wypowiedział". „Tak" działało,
        // bo `(string) true` to „1" — błąd dotyczył wyłącznie jednej z dwóch wartości.
        // `null` zostaje `null`: trzeci stan ma pozostać trzecim stanem.
        $data['position_attributes'] = $record->attributeValues
            ->filter(fn ($value): bool => $value->attribute !== null)
            ->mapWithKeys(function ($value): array {
                $typed = $value->typedValue($value->attribute->type);

                return [$value->position_attribute_id => is_bool($typed) ? (int) $typed : $typed];
            })
            ->toArray();

        $data['groups'] = $record->groups->pluck('id')->toArray();

        return $data;
    }

    /**
     * ⚠️ Bez tego zawężenia widoczność stanowisk stała WYŁĄCZNIE na publicznej
     * właściwości `ListPositions::$fisheryId`, sprawdzanej raz w `mount()` — czyli
     * na danych od klienta. Podmiana jej w kolejnym żądaniu Livewire (albo
     * wyzerowanie, bo filtr w `getTableQuery()` jest warunkowy) wypisywała cudze
     * rekordy. `CLAUDE.md`: pobranie z **zakresem widoczności**, nie sam filtr
     * z żądania. Panel admina celowo bez zawężenia — widzi wszystko.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        FisheryAccess::scopeToOwnedFisheries($query);

        // ⚠️ Eager-load jest tu WYMAGANY, nie kosmetyczny: `visible()` akcji wiersza
        // pyta politykę, a ta dla właściciela sięga po `$record->fishery->user_id` —
        // bez tego każdy wiersz tabeli dociąga własne zapytanie o łowisko.
        return $query->with('fishery');
    }
}
