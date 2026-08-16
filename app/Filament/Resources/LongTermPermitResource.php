<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LongTermPermitResource\Pages\CreateLongTermPermit;
use App\Filament\Resources\LongTermPermitResource\Pages\EditLongTermPermit;
use App\Filament\Resources\LongTermPermitResource\Pages\ListLongTermPermits;
use App\Helpers\Helper;
use App\Models\LongTermPermit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LongTermPermitResource extends Resource
{
    protected static ?string $model = LongTermPermit::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check';

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
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(function (string $state) {
                        return strip_tags($state);
                    })
                    ->limit(20),
                TextColumn::make('valid_from')
                    ->label(__('Valid from'))
                    ->date('d.m.Y')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('valid_to')
                    ->label(__('Valid to'))
                    ->date('d.m.Y')
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
            'index' => ListLongTermPermits::route('/'),
            'create' => CreateLongTermPermit::route('/create'),
            'edit' => EditLongTermPermit::route('/{record}/edit'),
        ];
    }

    public static function getPluralLabel(): ?string
    {
        return __('Long term permits');
    }

    /**
     * ⚠️ Zawężenie do łowisk właściciela — patrz komentarz w `PositionResource`.
     * Bez niego widoczność stała na publicznej właściwości `$fisheryId` strony listy.
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
