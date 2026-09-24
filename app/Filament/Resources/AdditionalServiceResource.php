<?php

namespace App\Filament\Resources;

use App\Enums\PositionAttributeType;
use App\Enums\ServiceBillingUnit;
use App\Enums\ServiceScope;
use App\Filament\Resources\AdditionalServiceResource\Pages\CreateAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\EditAdditionalService;
use App\Filament\Resources\AdditionalServiceResource\Pages\ListAdditionalServices;
use App\Models\AdditionalService;
use App\Models\PositionAttribute;
use App\Rules\PositionAttributesAreFlags;
use App\Services\FisheryAccess;
use App\Services\SharedFormComponents;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AdditionalServiceResource extends Resource
{
    protected static ?string $model = AdditionalService::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...SharedFormComponents::getFisheryFields(),
                TextInput::make('name')
                    ->label(__('Additional service name'))
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label(__('Is additional service active')),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions()),
                // ⚠️ Minimum 0,00, nie 0,01: usługa darmowa („postawienie przyczepy", O21) nadal
                // niesie deklarację wędkarza i limit egzemplarzy (zadanie 020).
                SharedFormComponents::getPriceInput('price', null, 0.00),
                ToggleButtons::make('billing_unit')
                    ->label(__('Billing unit'))
                    ->options(ServiceBillingUnit::options())
                    ->default(ServiceBillingUnit::PerNight->value)
                    ->inline()
                    ->required()
                    ->helperText(__('Per night: price × nights of the stay × quantity. Per stay: price × quantity.')),
                TextInput::make('available_count')
                    ->label(__('Available count'))
                    ->numeric()
                    ->rules(['nullable', 'integer', 'min:1'])
                    ->helperText(__('Units available on every night — a unit taken for a stay is taken on each of its nights. Leave empty for no limit.')),
                ToggleButtons::make('scope')
                    ->label(__('Available on'))
                    ->options(ServiceScope::options())
                    ->default(ServiceScope::SelectedPositions->value)
                    ->inline()
                    ->required()
                    ->live(),
                // ⚠️ Usługa „wybrane stanowiska" bez przypięć jest dostępna NIGDZIE — odpięcie
                // ostatniego stanowiska nie otwiera jej po cichu na całym łowisku.
                Callout::make(__('This service is available nowhere yet'))
                    ->description(__('Pin it to positions on the position form or with the "Pin a service" action on the positions list.'))
                    ->warning()
                    ->visible(fn (Get $get, ?AdditionalService $record): bool => self::scopeOf($get('scope')) === ServiceScope::SelectedPositions
                        && self::pinCount($record) === 0),
                // ⚠️ Zmiana zasięgu na „całe łowisko" USUWA przypięcia (hak modelu). Formularz pyta
                // o zgodę i podaje ich liczbę — bez tej zgody zapis nie przechodzi.
                Checkbox::make('confirm_unpin')
                    ->label(fn (?AdditionalService $record): string => trans_choice(
                        'I understand that the service will be unpinned from :count position|I understand that the service will be unpinned from :count positions',
                        self::pinCount($record),
                        ['count' => self::pinCount($record)],
                    ))
                    ->accepted()
                    ->dehydrated(false)
                    ->visible(fn (Get $get, ?AdditionalService $record): bool => self::scopeOf($get('scope')) === ServiceScope::WholeFishery
                        && $record?->scope === ServiceScope::SelectedPositions
                        && self::pinCount($record) > 0),
                // ⚠️ Nie `->relationship()`: zapis idzie przez bramkę
                // `AdditionalServiceSync::syncRequiredAttributes()` (tylko flagi, wpis w dzienniku
                // zmian, zachowanie wymogu cechy usuniętej miękko) — wołają ją strony zasobu.
                Select::make('required_attribute_ids')
                    ->label(__('Required position attributes'))
                    ->helperText(__('The service is unavailable on a position that lacks any of them — set to "no", not specified, or suspended by a restriction.'))
                    ->multiple()
                    ->options(fn (): array => PositionAttribute::query()
                        ->where('type', PositionAttributeType::Flag->value)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray())
                    ->rules([new PositionAttributesAreFlags]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_active')
                    ->label(__('Is additional service active')),
                TextColumn::make('name')
                    ->label(__('Additional service name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->searchable()
                    ->formatStateUsing(function (string $state) {
                        return strip_tags($state);
                    })
                    ->limit(20),
                TextColumn::make('price')
                    ->label(__('Price'))
                    ->state(fn (AdditionalService $record): string => $record->priceLabel($record->fishery?->currency?->name))
                    ->sortable(),
                TextColumn::make('scope')
                    ->label(__('Available on'))
                    ->badge()
                    ->state(fn (AdditionalService $record): string => self::scopeLabel($record))
                    ->color(fn (AdditionalService $record): string => self::isAvailableNowhere($record) ? 'warning' : 'gray'),
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

    /**
     * Stan pola `ToggleButtons` bywa enumem albo łańcuchem — zależnie od tego, czy przyszedł
     * z rekordu, czy z żądania.
     */
    private static function scopeOf(mixed $state): ?ServiceScope
    {
        return $state instanceof ServiceScope ? $state : ServiceScope::tryFrom((string) $state);
    }

    private static function pinCount(?AdditionalService $record): int
    {
        return $record?->exists ? $record->positions()->count() : 0;
    }

    private static function scopeLabel(AdditionalService $record): string
    {
        $scope = $record->scope ?? ServiceScope::SelectedPositions;

        if ($scope === ServiceScope::WholeFishery) {
            return $scope->label();
        }

        return self::isAvailableNowhere($record)
            ? __('Selected positions — none pinned')
            : trans_choice(':count position|:count positions', (int) $record->positions_count, ['count' => (int) $record->positions_count]);
    }

    /**
     * ⚠️ Liczy z `positions_count` (dokładają go `getEloquentQuery()` i strona łowiska), a nie
     * zapytaniem na wiersz.
     */
    private static function isAvailableNowhere(AdditionalService $record): bool
    {
        return ($record->scope ?? ServiceScope::SelectedPositions) === ServiceScope::SelectedPositions
            && (int) $record->positions_count === 0;
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
            'index' => ListAdditionalServices::route('/'),
            'create' => CreateAdditionalService::route('/create'),
            'edit' => EditAdditionalService::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * ⚠️ Zawężenie do łowisk właściciela — patrz komentarz w `PositionResource`.
     * Bez niego widoczność stała na publicznej właściwości `$fisheryId` strony listy.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        FisheryAccess::scopeToOwnedFisheries($query);

        // ⚠️ Eager-load jest tu WYMAGANY, nie kosmetyczny: `visible()` akcji wiersza
        // pyta politykę, a ta dla właściciela sięga po `$record->fishery->user_id` —
        // bez tego każdy wiersz tabeli dociąga własne zapytanie o łowisko. Waluta i licznik
        // przypięć idą z tego samego powodu: kolumny ceny i zasięgu czytają je na każdym wierszu.
        return $query->with('fishery.currency')->withCount('positions');
    }
}
