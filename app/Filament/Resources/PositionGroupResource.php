<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PositionGroupResource\Pages\CreatePositionGroup;
use App\Filament\Resources\PositionGroupResource\Pages\EditPositionGroup;
use App\Filament\Resources\PositionGroupResource\Pages\ListPositionGroups;
use App\Models\Position;
use App\Models\PositionGroup;
use App\Rules\RecordsBelongToFishery;
use App\Services\FisheryAccess;
use App\Services\SharedFormComponents;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Grupa stanowisk — zasób współdzielony przez oba panele, wzorowany na
 * `PositionResource`: bez pozycji w nawigacji, wejście przez zakładkę huba.
 *
 * ⚠️ Panel administratora odkrywa zasoby katalogiem, ale panel właściciela ma listę
 * JAWNĄ — bez wpisu w `OwnerPanelProvider` grupy byłyby w nim niewidoczne.
 */
class PositionGroupResource extends Resource
{
    protected static ?string $model = PositionGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...SharedFormComponents::getFisheryFields(),
                TextInput::make('name')
                    ->label(__('Group name'))
                    ->required()
                    ->maxLength(255),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->helperText(__('Everything a flag can not carry: directions, the character of the bank.'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions()),
                Select::make('positions')
                    ->label(__('Positions'))
                    ->relationship('positions', 'name')
                    ->multiple()
                    ->preload()
                    // ⚠️ Lista stanowisk liczy się z `fishery_id`, czyli z pola `Hidden`,
                    // czyli z danych od klienta. Bez zawężenia podmiana stanu wyświetliłaby
                    // nazwy stanowisk CUDZEGO łowiska (`autoryzacja.md` §4).
                    ->options(function (Get $get): array {
                        $fisheryId = $get('fishery_id');

                        if (! is_numeric($fisheryId)) {
                            return [];
                        }

                        $query = Position::query()->where('fishery_id', (int) $fisheryId);
                        FisheryAccess::scopeToOwnedFisheries($query);

                        return $query->pluck('name', 'id')->toArray();
                    })
                    // ⚠️ Reguła, NIE samo zawężenie opcji — Filament nie sprawdza, czy
                    // przysłane identyfikatory pochodzą z wyrenderowanej listy. Bez tego
                    // dało się podpiąć stanowiska cudzego łowiska do własnej grupy,
                    // a potem akcją zbiorczą zapisać na nich wartości cech.
                    ->rules([
                        fn (Get $get): RecordsBelongToFishery => new RecordsBelongToFishery(
                            Position::class,
                            $get('fishery_id'),
                        ),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group name'))
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->formatStateUsing(fn (?string $state): string => strip_tags((string) $state))
                    ->limit(20),
                TextColumn::make('positions_count')
                    ->label(__('Positions'))
                    ->counts('positions'),
            ])
            ->recordActions([
                EditAction::make(),
                // ⚠️ SKRÓT do akcji zbiorczej z tabeli stanowisk — ten sam schemat pól
                // i ten sam kod zapisu, tylko z zaznaczeniem wypełnionym stanowiskami
                // grupy. Wartość ląduje WPROST na każdym stanowisku; grupa niczego nie
                // dziedziczy i po wykonaniu nie trzyma żadnego stanu (zadanie 014).
                Action::make('setPositionAttribute')
                    ->label(__('Set an attribute'))
                    ->icon('heroicon-m-tag')
                    ->schema(PositionResource::attributeAssignmentSchema())
                    ->requiresConfirmation()
                    ->modalDescription(fn (PositionGroup $record): string => trans_choice(
                        'The attribute will be set on :count position|The attribute will be set on :count positions',
                        $record->positions()->count(),
                        ['count' => $record->positions()->count()],
                    ))
                    ->visible(fn (PositionGroup $record): bool => Gate::allows('update', $record))
                    ->action(fn (PositionGroup $record, array $data) => PositionResource::applyAttributeAssignment(
                        $record->positions()->get(),
                        $data,
                    )),
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
            'index' => ListPositionGroups::route('/'),
            'create' => CreatePositionGroup::route('/create'),
            'edit' => EditPositionGroup::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Position groups');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Position groups');
    }

    public static function getModelLabel(): string
    {
        return __('position group');
    }

    /**
     * ⚠️ Jedyna warstwa działająca na odczycie POJEDYNCZEGO rekordu
     * (`resolveRecordRouteBinding()` na stronie edycji) — bez niej da się wejść
     * na cudzą grupę wprost z adresu (`autoryzacja.md` §4).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        FisheryAccess::scopeToOwnedFisheries($query);

        // Eager-load wymagany: `visible()` akcji wiersza pyta politykę, a ta sięga
        // po `$record->fishery->user_id` — bez tego N+1 na całą tabelę.
        return $query->with('fishery');
    }
}
