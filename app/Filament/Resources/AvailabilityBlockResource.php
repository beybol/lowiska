<?php

namespace App\Filament\Resources;

use App\Enums\BlockEffect;
use App\Enums\PositionAttributeType;
use App\Enums\SelectionKind;
use App\Filament\Resources\AvailabilityBlockResource\Pages\CreateAvailabilityBlock;
use App\Filament\Resources\AvailabilityBlockResource\Pages\EditAvailabilityBlock;
use App\Filament\Resources\AvailabilityBlockResource\Pages\ListAvailabilityBlocks;
use App\Helpers\Helper;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttribute;
use App\Models\PositionGroup;
use App\Rules\AvailabilityBlockEffectMatchesAttribute;
use App\Rules\PositionsBelongToFishery;
use App\Services\AvailabilityBlockSelectionResolver;
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
            ->components([
                ...Helper::getFisheryFields(),
                Section::make(__('Effect and period'))
                    ->schema([
                        Select::make('effect')
                            ->label(__('Effect'))
                            ->options(BlockEffect::options())
                            ->default(BlockEffect::SaleBlocked->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->required(),
                        Select::make('position_attribute_id')
                            ->label(__('Suspended attribute'))
                            // Wyłącznie cechy tak/nie — zawiesza się to, co stanowisko MA
                            // albo czego NIE MA (zadanie 016, „Rozstrzygnięcia").
                            ->options(fn (): array => PositionAttribute::query()
                                ->where('type', PositionAttributeType::Flag->value)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
                            ->visible(fn (Get $get): bool => $get('effect') === BlockEffect::AttributeSuspended->value)
                            ->rules([
                                fn (Get $get): AvailabilityBlockEffectMatchesAttribute => new AvailabilityBlockEffectMatchesAttribute(
                                    BlockEffect::tryFrom((string) $get('effect')),
                                ),
                            ]),
                        DatePicker::make('starts_on')
                            ->label(__('From'))
                            ->required(),
                        DatePicker::make('ends_on')
                            ->label(__('To'))
                            ->helperText(__('Leave empty for an entry valid until revoked.'))
                            ->afterOrEqual('starts_on'),
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
                            ->options(SelectionKind::options())
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
                            ->options(fn (): array => PositionAttribute::query()
                                ->where('type', PositionAttributeType::Flag->value)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->toArray())
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
                        ]),
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
                            ]),
                    ]),
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

        Helper::scopeToOwnedFisheries($query);

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

        $data['selection_label'] = match ($kind) {
            SelectionKind::Group => PositionGroup::find($data['position_group_id'] ?? null)?->name,
            SelectionKind::Attribute => PositionAttribute::find($data['selection_attribute_id'] ?? null)?->name,
            default => null,
        };

        // Pola kryterium nie są kolumnami — zostawione w tablicy trafiłyby do `fill()`.
        unset($data['position_group_id'], $data['selection_attribute_id']);

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

        $query = Fishery::query()->whereKey((int) $fisheryId);
        Helper::scopeToOwnedFisheries($query);
        $fishery = $query->first();

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
     * @return array<int, string>
     */
    private static function positionOptions(mixed $fisheryId): array
    {
        if (! is_numeric($fisheryId)) {
            return [];
        }

        $query = Position::query()->where('fishery_id', (int) $fisheryId)->orderBy('name');
        Helper::scopeToOwnedFisheries($query);

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
        Helper::scopeToOwnedFisheries($query);

        return $query->pluck('name', 'id')->toArray();
    }
}
