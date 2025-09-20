<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LongTermPermitResource\Pages;
use App\Filament\Resources\LongTermPermitResource\RelationManagers;
use App\Models\LongTermPermit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\RichEditor;
use App\Helpers\Helper;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;

class LongTermPermitResource extends Resource
{
    protected static ?string $model = LongTermPermit::class;

    protected static ?string $navigationIcon = 'heroicon-o-check';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                ...Helper::getFisheryFields(),
                Toggle::make('is_active')
                    ->label(__('Is active')),
                RichEditor::make('description')
                    ->label(__('Description'))
                    ->required()
                    ->toolbarButtons(Helper::getRichEditorOptions()),
                DatePicker::make('valid_from')
                    ->label(__('Valid from'))
                    ->reactive(),
                DatePicker::make('valid_to')
                    ->label(__('Valid to'))
                    ->reactive()
                    ->minDate(fn (callable $get) => $get('valid_from')),
                Helper::getPriceInput(),
                TextInput::make('sales_limit')
                    ->label(__('Sales limit'))
                    ->numeric()
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->helperText(__('Enter 0 for unlimited sales.')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_active')
                    ->label(__('Is active')),
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->searchable()
                    ->formatStateUsing(function (string $state) {
                        return strip_tags($state);
                    })
                    ->limit(20),
                TextColumn::make('valid_from')
                    ->label(__('Valid from'))
                    ->date()
                    ->sortable(),
                TextColumn::make('valid_to')
                    ->label(__('Valid to'))
                    ->date()
                    ->sortable(),
                TextColumn::make('fishery.name')
                    ->label(__('Fishery'))
                    ->sortable()
                    ->visible(fn () => !request()->has('fishery')),
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
        $fisheryId = request()->get('fishery');
        
        return [
            'index' => Pages\ListLongTermPermits::route('/'),
            'create' => Pages\CreateLongTermPermit::route('/create'),
            'edit' => Pages\EditLongTermPermit::route('/{record}/edit'),
        ];
    }

    public static function getPluralLabel(): ?string
    {
        return __('Long term permits');
    }
}
