<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PositionResource\Pages\CreatePosition;
use App\Filament\Resources\PositionResource\Pages\EditPosition;
use App\Filament\Resources\PositionResource\Pages\ListPositions;
use App\Helpers\Helper;
use App\Models\AdditionalService;
use App\Models\LongTermPermit;
use App\Models\Position;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                ...Helper::getFisheryFields(),
                Toggle::make('is_active')
                    ->label(__('Is active')),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label(__('Position name')),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->toolbarButtons(Helper::getRichEditorOptions()),
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
                        Helper::scopeToOwnedFisheries($query);

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
                        Helper::scopeToOwnedFisheries($query);

                        return $query->exists();
                    }),
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
                                $query = AdditionalService::forFishery($fisheryId)
                                    ->isActive();
                                Helper::scopeToOwnedFisheries($query);

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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_active')
                    ->label(__('Is active')),
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
                    DeleteBulkAction::make(),
                ]),
            ]);
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

        Helper::scopeToOwnedFisheries($query);

        // ⚠️ Eager-load jest tu WYMAGANY, nie kosmetyczny: `visible()` akcji wiersza
        // pyta politykę, a ta dla właściciela sięga po `$record->fishery->user_id` —
        // bez tego każdy wiersz tabeli dociąga własne zapytanie o łowisko.
        return $query->with('fishery');
    }
}
