<?php

namespace App\Filament\Resources;

use App\Enums\SignInMethod;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('surname')
                    ->label(__('Surname'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label(__('E-mail'))
                    ->email()
                    ->required()
                    ->maxLength(255),
                // `form()` jest współdzielone między CreateUser i EditUser — stąd required()
                // tylko dla operacji create i dehydrated() tylko gdy wypełnione, żeby edycja
                // innych pól nie wymuszała podania hasła ani go nie zerowała. `User::$casts`
                // ma `password => 'hashed'`, więc model sam haszuje wartość przy zapisie —
                // nie wołać tu dodatkowo Hash::make().
                TextInput::make('password')
                    ->label(__('Password'))
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label(__('Phone (without prefix)'))
                    ->tel()
                    ->maxLength(255)
                    ->default(null),
                Select::make('country_id')
                    ->label(__('Country prefix'))
                    ->relationship('country', 'prefix'),
                Toggle::make('is_admin')->label(__('Is admin')),
                CheckboxList::make('roles')
                    ->relationship('roles', 'name')
                    ->label(__('Roles'))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('surname')
                    ->label(__('Surname'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('E-mail'))
                    ->searchable(),
                ToggleColumn::make('is_admin')
                    ->label(__('Is admin')),
                TextColumn::make('roles.name')
                    ->badge()
                    ->label(__('Roles'))
                    ->searchable(),
                // Sposób logowania (zadanie 028) — z `provider` i `has_password`, reguła w `SignInMethod`.
                TextColumn::make('sign_in_method')
                    ->label(__('Sign-in'))
                    ->badge()
                    ->state(fn (User $record): string => SignInMethod::of($record)->labelFor($record->provider))
                    ->color(fn (User $record): string => SignInMethod::of($record) === SignInMethod::Password ? 'gray' : 'info'),
            ])
            ->filters([
                SelectFilter::make('sign_in_method')
                    ->label(__('Sign-in'))
                    ->options(SignInMethod::options())
                    ->query(fn (Builder $query, array $data): Builder => ($method = SignInMethod::tryFrom((string) ($data['value'] ?? ''))) === null
                        ? $query
                        : $method->scope($query)),
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('Access');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Users');
    }

    public static function getModelLabel(): string
    {
        return __('user');
    }
}
