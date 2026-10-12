<?php

namespace App\Filament\Resources;

use App\Enums\DocumentChangeKind;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\PlatformDocumentResource\Pages\CreatePlatformDocument;
use App\Filament\Resources\PlatformDocumentResource\Pages\EditPlatformDocument;
use App\Filament\Resources\PlatformDocumentResource\Pages\ListPlatformDocuments;
use App\Models\PlatformDocument;
use App\Rules\DocumentEffectiveDateIsAhead;
use App\Rules\DocumentEffectiveDateIsFree;
use App\Services\FisheryDocuments;
use App\Services\PlatformDocuments;
use App\Services\PortalRoutes;
use App\Services\SharedFormComponents;
use App\Support\RowActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * Dokumenty Fisherya — regulamin i polityka prywatności portalu, wersjonowane (zadanie 049, ADR-028).
 * Wyłącznie w panelu administratora (zasobu nie ma na liście `OwnerPanelProvider`).
 *
 * ⚠️ Te same reguły wersji co dokument łowiska (`Document`, ADR-017): wersja obowiązuje od północy dnia
 * wejścia w życie (strefa domyślna portalu) i od tej chwili jest nienaruszalna razem z wariantami
 * językowymi — pilnuje tego MODEL, wyłączone pola formularza są tylko wygodą. Usunąć wolno wyłącznie
 * wersję zaplanowaną. Flaga „wymagany przy zakładaniu konta" jest edytowalna zawsze.
 *
 * Treść wiążąca jest w języku wersji (`locale`); warianty w innych językach to tłumaczenia tej samej treści.
 */
class PlatformDocumentResource extends Resource
{
    protected static ?string $model = PlatformDocument::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    public static function form(Schema $schema): Schema
    {
        $locked = fn (?PlatformDocument $record): bool => $record instanceof PlatformDocument && FisheryDocuments::isLocked($record);

        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        Select::make('type')
                            ->label(__('Document'))
                            ->options(DocumentType::options())
                            ->required()
                            ->live()
                            ->disabled(fn (?PlatformDocument $record): bool => $record instanceof PlatformDocument),
                        Select::make('locale')
                            ->label(__('Binding language'))
                            ->options(self::localeOptions())
                            ->default(PortalRoutes::DEFAULT_LOCALE)
                            ->required()
                            ->disabled($locked),
                        TextInput::make('title')
                            ->label(__('Title'))
                            ->required()
                            ->maxLength(255)
                            ->disabled($locked),
                        DatePicker::make('effective_from')
                            ->label(__('Takes effect on'))
                            ->required()
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->default(fn (): string => PlatformDocuments::defaultEffectiveFrom()->toDateString())
                            ->helperText(__('From this date the version is in force and can no longer be edited or deleted. It takes effect at midnight (Europe/Warsaw).'))
                            ->disabled($locked)
                            // ⚠️ Filament waliduje także pole WYŁĄCZONE — przy wersji obowiązującej reguły daty
                            // odrzuciłyby sam zapis flagi. Datę wersji obowiązującej chroni model.
                            ->rules(fn (?PlatformDocument $record, Get $get): array => $locked($record) ? [] : [
                                new DocumentEffectiveDateIsAhead(null),
                                new DocumentEffectiveDateIsFree(null, $record instanceof PlatformDocument ? $record->type : $get('type'), $record?->getKey()),
                            ]),
                        Select::make('change_kind')
                            ->label(__('Kind of change'))
                            ->options(DocumentChangeKind::options())
                            ->default(DocumentChangeKind::Notice->value)
                            ->required()
                            ->disabled($locked),
                        DatePicker::make('announced_on')
                            ->label(__('Announced on'))
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->beforeOrEqual('effective_from')
                            ->disabled($locked),
                        Toggle::make('required_at_registration')
                            ->label(__('Required when creating an account'))
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
                RichEditor::make('content')
                    ->label(__('Content'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                    ->required()
                    ->disabled($locked),
                Repeater::make('translations')
                    ->label(__('Language versions'))
                    ->helperText(__('A translation of the same content. The binding language is the one selected above.'))
                    ->relationship()
                    ->defaultItems(0)
                    ->disabled($locked)
                    ->schema([
                        Select::make('locale')
                            ->label(__('Language'))
                            ->options(self::localeOptions())
                            ->required()
                            ->distinct(),
                        TextInput::make('title')
                            ->label(__('Title'))
                            ->required()
                            ->maxLength(255),
                        RichEditor::make('content')
                            ->label(__('Content'))
                            ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $documents = new PlatformDocuments;

        return $table
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable(),
                TextColumn::make('locale')
                    ->label(__('Binding language'))
                    ->formatStateUsing(fn (string $state): string => self::localeOptions()[$state] ?? $state),
                TextColumn::make('effective_from')
                    ->label(__('Takes effect on'))
                    ->date('d.m.Y'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (PlatformDocument $record): string => $documents->statusOf($record)->label())
                    ->color(fn (PlatformDocument $record): string => $documents->statusOf($record)->color()),
                TextColumn::make('change_kind')
                    ->label(__('Kind of change'))
                    ->formatStateUsing(fn (DocumentChangeKind $state): string => $state->label()),
                IconColumn::make('required_at_registration')
                    ->label(__('Required when creating an account'))
                    ->boolean(),
            ])
            ->defaultGroup(Group::make('type')
                ->label(__('Document'))
                ->getTitleFromRecordUsing(fn (PlatformDocument $record): string => $record->type->label()))
            ->recordActions([RowActions::menu([
                EditAction::make(),
                DeleteAction::make()
                    // Usunąć wolno wyłącznie wersję zaplanowaną — nikt jej jeszcze nie zaakceptował.
                    ->visible(fn (PlatformDocument $record): bool => $documents->statusOf($record) === DocumentStatus::Scheduled),
            ])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformDocuments::route('/'),
            'create' => CreatePlatformDocument::route('/create'),
            'edit' => EditPlatformDocument::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        return ['pl' => __('Polish'), 'en' => __('English')];
    }

    public static function getModelLabel(): string
    {
        return __('Fisherya document');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Fisherya documents');
    }

    public static function getNavigationLabel(): string
    {
        return __('Fisherya documents');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('Dictionaries');
    }

    public static function getNavigationSort(): ?int
    {
        return 21;
    }
}
