<?php

namespace App\Filament\Resources;

use App\Enums\DocumentType;
use App\Filament\Resources\DocumentTemplateResource\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplateResource\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplateResource\Pages\ListDocumentTemplates;
use App\Models\DocumentTemplate;
use App\Services\SharedFormComponents;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

/**
 * Szablony dokumentów — wyłącznie w panelu administratora (zadanie 021, ADR-017).
 *
 * ⚠️ Zasobu NIE MA na liście `OwnerPanelProvider`: właściciel czyta szablony tylko przez listę
 * przy nowej wersji dokumentu i podgląd (`PreviewDocumentTemplate`). Treść szablonów przygotowuje
 * Fisherya z prawnikiem (TODO-1, TODO-3); za treść dokumentu łowiska odpowiada łowisko (D9).
 */
class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // ⚠️ Typ porządkuje i podpowiada, NIE ogranicza: przy nowej wersji dokumentu
                // operator może wybrać dowolny szablon, także innego typu.
                Select::make('type')
                    ->label(__('Document type'))
                    ->options(DocumentType::options())
                    ->required(),
                TextInput::make('name')
                    ->label(__('Template name'))
                    ->required()
                    ->maxLength(255),
                RichEditor::make('content')
                    ->label(__('Content'))
                    ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('Document type'))
                    ->badge()
                    ->formatStateUsing(fn (DocumentType $state): string => $state->label()),
                TextColumn::make('name')
                    ->label(__('Template name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('Updated'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultGroup(Group::make('type')
                ->label(__('Document type'))
                ->getTitleFromRecordUsing(fn (DocumentTemplate $record): string => $record->type->label()))
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
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return __('document template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Document templates');
    }

    public static function getNavigationLabel(): string
    {
        return __('Document templates');
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('Dictionaries');
    }

    public static function getNavigationSort(): ?int
    {
        return 20;
    }
}
