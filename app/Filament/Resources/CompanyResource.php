<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Helpers\Helper;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(static::formComponents());
    }

    /**
     * Pola formularza firmy jako lista komponentów.
     *
     * Wydzielone z `form()`, żeby kreator zakładania łowiska
     * (`FisheryResource\Pages\CreateFishery`) mógł osadzić ten sam formularz
     * w swoim kroku „nowa firma", zamiast powielać definicje pól (zadanie 012).
     *
     * ⚠️ `unique()` na `tin`/`renae` MUSI mieć jawnie podaną tabelę — bez tego
     * Filament wnioskuje ją z modelu formularza, a wewnątrz kreatora tym modelem
     * jest `Fishery`, nie `Company`. Reguła sprawdzałaby wtedy nieistniejące
     * kolumny w tabeli łowisk.
     *
     * @return array<int, mixed>
     */
    public static function formComponents(): array
    {
        return [
            Toggle::make('is_verified')
                ->hidden(fn () => Helper::isOwnerPanel())
                ->label(__('Verified')),
            Select::make('user_id')
                ->required()
                ->hidden(fn () => Helper::isOwnerPanel())
                ->label(__('Company entered by'))
                ->disabled()
                ->relationship('user', 'name')
                ->getOptionLabelFromRecordUsing(function (User $user) {
                    return $user->getFilamentName();
                })
                ->default(function (?Company $record) {
                    return $record === null
                        ? auth()->id()
                        : $record->user_id;
                }),
            Section::make(__('Get data from CSO'))
                ->schema([
                    Placeholder::make('Enter CSO/RENAE number below.')
                        ->content(__('Enter CSO/RENAE number below.')),
                    TextInput::make('tin')
                        ->label(__('TIN'))
                        ->validationAttribute(__('TIN'))
                        ->unique(table: Company::class, ignoreRecord: true),
                    TextInput::make('renae')
                        ->label(__('RENAE'))
                        ->validationAttribute(__('REGON'))
                        ->unique(table: Company::class, ignoreRecord: true),
                    Actions::make([
                        Action::make('fetch_cso_data')
                            ->label(__('Get data from CSO'))
                            ->action(Helper::fetchDataFromCSO(...)),
                    ]),
                    // ⚠️ Nazwa tego komponentu NIE MOŻE być równa kluczowi stanu,
                    // który sam odczytuje (`error`, ustawiany przez
                    // Helper::fetchDataFromCSO). Do Filamenta 3 nazywał się
                    // `error` i działało; od Filamenta 4/5 (wspólny Schema)
                    // `$get('error')` wewnątrz zawartości komponentu o tej samej
                    // nazwie odpytuje sam siebie — rekurencja bez dna, która
                    // zjadała ponad 6 GB i wywracała proces, a nie rzucała
                    // czytelnym błędem (zadanie 009).
                    // ⚠️ `->label('')` NIE ukrywa etykiety — Filament traktuje pusty ciąg jak
                    // brak ustawienia i wypisuje nazwę komponentu („Cso error message").
                    // Do ukrycia służy `hiddenLabel()`. Dodatkowo cały komunikat pokazuje
                    // się dopiero, gdy jest co pokazać (zadanie 012).
                    Placeholder::make('cso_error_message')
                        ->content(function (Get $get) {
                            return new HtmlString(
                                '<div class="text-danger-600">'
                                    .$get('error')
                                    .'</div>'
                            );
                        })
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => filled($get('error'))),
                    TextInput::make('cso_response')
                        ->label(__('CSO response'))
                        ->readonly(),
                ]),
            TextInput::make('name')
                ->label(__('Company name'))
                ->required(),
            TextInput::make('street')
                ->label(__('Street'))
                ->required(),
            TextInput::make('house_number')
                ->label(__('House number'))
                ->required(),
            TextInput::make('flat_number')
                ->label(__('Flat number')),
            TextInput::make('postal_code')
                ->label(__('Postal code'))
                ->required(),
            TextInput::make('city')
                ->label(__('City'))
                ->required(),
            Select::make('state_id')
                ->label(__('State'))
                ->options(Helper::sortStates())
                ->searchable()
                ->required(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_verified')
                    ->label(__('Verified'))
                    ->hidden(fn () => Helper::isOwnerPanel()),
                TextColumn::make('name')
                    ->label(__('Company name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('city')
                    ->label(__('City'))
                    ->sortable()
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
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Companies');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Companies');
    }

    public static function getModelLabel(): string
    {
        return __('company');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (Helper::isOwnerPanel()) {
            $query->forCurrentUser();
        }

        return $query->with('user', 'state');
    }
}
