<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
                TextInput::make('phone')
                    ->label(__('Phone (without prefix)'))
                    ->tel()
                    ->maxLength(255)
                    ->default(null),
                Select::make('country_id')
                    ->label(__('Country prefix'))
                    ->relationship('country', 'country_name'),
                Toggle::make('is_admin')->label(__('Is admin')),
                Toggle::make('is_owner')->label(__('Is fishery owner')),
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
                ToggleColumn::make('is_owner')
                    ->label(__('Is fishery owner')),
                TextColumn::make('roles.name')
                    ->badge()
                    ->label(__('Roles'))
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Users');
    }

    public static function getPluralLabel(): ?string
    {
        return __('Users');
    }

    public static function getModelLabel(): string {
        return __('user');
    }
}
