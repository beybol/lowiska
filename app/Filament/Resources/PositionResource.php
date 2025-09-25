<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PositionResource\Pages;
use App\Filament\Resources\PositionResource\RelationManagers;
use App\Models\Position;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Forms\Components\RichEditor;
use App\Helpers\Helper;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
use App\Models\LongTermPermit;
use App\Models\AdditionalService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Get;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

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

                        if (!$fisheryId) {
                            return [];
                        }

                        return LongTermPermit::query()
                            ->forFishery($fisheryId)
                            ->isActive()
                            ->get()
                            ->mapWithKeys(function($item) {
                                return [$item->id 
                                    => strip_tags($item->description)];
                            })
                            ->toArray();
                    })
                    ->reactive()
                    ->columnSpan('full')
                    ->visible(function (callable $get) {
                        $fisheryId = $get('fishery_id');

                        if (!$fisheryId) {
                            return false;
                        }
                        
                        return LongTermPermit::query()
                            ->forFishery($fisheryId)
                            ->isActive()
                            ->exists();
                    }),
                Repeater::make('additionalServices')
                    ->statePath('additionalServices')
                    ->schema([
                        Select::make('additional_service_id')
                            ->label(__('Additional service'))
                            ->options(function (Get $get) {
                                $fisheryId = $get('../../fishery_id') ?? request()->get('fishery');

                                if (!$fisheryId) {
                                    return [];
                                }

                                return AdditionalService::forFishery($fisheryId)
                                    ->isActive()
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems(true)
                            ->required(),
                        Checkbox::make('is_required')
                            ->label(__('Is required')),
                    ])
                    ->label(__('Additional services'))
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
            'index' => Pages\ListPositions::route('/'),
            'create' => Pages\CreatePosition::route('/create'),
            'edit' => Pages\EditPosition::route('/{record}/edit'),
        ];
    }

    public static function getEloquentFormData($record): array
    {
        $data = $record->toArray();
        unset($data['additional_services']);
        $data['additionalServices'] = $record->additionalServices
            ->map(fn($service) => [
                'additional_service_id' => $service->id,
                'is_required' => $service->pivot->is_required,
            ])
            ->toArray();
        $data['additionalServices'] = array_values($data['additionalServices'] ?? []);

        return $data;
    }
}
