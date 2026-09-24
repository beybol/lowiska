<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\FisheryResource;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Fishery;
use App\Rules\DocumentEffectiveDateIsAhead;
use App\Rules\DocumentEffectiveDateIsFree;
use App\Services\FisheryDocuments;
use App\Services\FisheryNavigation;
use App\Services\SharedFormComponents;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Ekran „Dokumenty" — wersje regulaminu i polityki prywatności łowiska (zadanie 021, ADR-017).
 *
 * ⚠️ **Za treść odpowiada łowisko** (D9): ekran niczego nie generuje z konfiguracji, nie wstawia
 * zasad zwrotu ani parametrów i nie sprawdza zgodności treści z ustawieniami.
 *
 * ⚠️ **Nienaruszalność wersji od dnia wejścia w życie pilnuje MODEL** (`Document`), po dacie
 * zapisanej w bazie — wyłączone pola formularza są tylko wygodą. Admin pracuje na tych samych
 * zasadach co operator.
 *
 * ⚠️ `Document` nie ma własnego zasobu ani polityki — każda akcja pyta jawnie
 * `FisheryPolicy::update()` na łowisku (`autoryzacja.md` §5). Rekordy akcji wiersza pochodzą
 * z tabeli po relacji łowiska, więc cudza wersja nie jest osiągalna; nowa wersja dostaje
 * `fishery_id` z rekordu strony, nigdy z formularza.
 */
class ManageDocuments extends ManageRelatedRecords
{
    protected static string $resource = FisheryResource::class;

    protected static string $relationship = 'documents';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    private ?FisheryDocuments $documents = null;

    public static function getNavigationLabel(): string
    {
        return __('Documents');
    }

    public function getTitle(): string
    {
        return __('Documents').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Documents');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Documents'));
    }

    /**
     * Zakładka na rodzaj dokumentu — rodzaje działają identycznie.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach (DocumentType::cases() as $type) {
            $tabs[$type->value] = Tab::make($type->label())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type->value));
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            // ⚠️ Stan wersji liczy się w strefie łowiska — bez eager-loadu każdy wiersz dociągałby
            // łowisko osobnym zapytaniem.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('fishery'))
            ->defaultSort('effective_from', 'desc')
            // ⚠️ Brak obowiązującego dokumentu to SAMA INFORMACJA, bez oceny treści (D9).
            ->description(fn (): ?string => $this->documents()->current($this->activeType()) === null
                ? __('This fishery has no :document in force.', ['document' => mb_strtolower($this->activeType()->label())])
                : null)
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable(),
                TextColumn::make('effective_from')
                    ->label(__('Takes effect on'))
                    ->date('d.m.Y'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Document $record): string => $this->documents()->statusOf($record)->label())
                    ->color(fn (Document $record): string => $this->documents()->statusOf($record)->color()),
                IconColumn::make('required_at_purchase')
                    ->label(__('Required at purchase'))
                    ->boolean(),
                IconColumn::make('required_at_registration')
                    ->label(__('Required at registration with the fishery'))
                    ->boolean(),
            ])
            ->headerActions([
                $this->newVersionAction(),
                $this->previewTemplateAction('previewTemplate'),
            ])
            ->recordActions([
                $this->editVersionAction(),
                $this->copyVersionAction(),
                $this->deleteVersionAction(),
            ]);
    }

    private function newVersionAction(): Action
    {
        return Action::make('createVersion')
            ->label(__('New version'))
            ->icon('heroicon-m-plus')
            ->slideOver()
            ->modalWidth(Width::Full)
            ->visible(fn (): bool => $this->canManageDocuments())
            ->authorize(fn (): bool => $this->canManageDocuments())
            ->fillForm(function (): array {
                $type = $this->activeType();
                $current = $this->documents()->current($type);

                // Domyślne źródło: kopia obowiązującej wersji, gdy taka istnieje.
                return $this->defaultsFor($type, $current instanceof Document ? 'copy:'.$current->getKey() : null);
            })
            ->schema(fn (): array => $this->versionSchema(null, withSource: true))
            ->action(fn (array $data) => $this->createVersion($data));
    }

    private function copyVersionAction(): Action
    {
        return Action::make('copyVersion')
            ->label(__('Copy as a new version'))
            ->icon('heroicon-m-document-duplicate')
            ->slideOver()
            ->modalWidth(Width::Full)
            ->visible(fn (): bool => $this->canManageDocuments())
            ->authorize(fn (): bool => $this->canManageDocuments())
            // Kopia może powstać z DOWOLNEJ wersji — obowiązującej, zaplanowanej albo archiwalnej.
            ->fillForm(fn (Document $record): array => $this->defaultsFor($record->type, 'copy:'.$record->getKey()))
            ->schema(fn (): array => $this->versionSchema(null, withSource: true))
            ->action(fn (array $data) => $this->createVersion($data));
    }

    private function editVersionAction(): Action
    {
        return Action::make('editVersion')
            ->label(__('Edit'))
            ->icon('heroicon-m-pencil-square')
            ->slideOver()
            ->modalWidth(Width::Full)
            ->visible(fn (): bool => $this->canManageDocuments())
            ->authorize(fn (): bool => $this->canManageDocuments())
            ->fillForm(fn (Document $record): array => [
                'type' => $record->type->value,
                'title' => $record->title,
                'effective_from' => $record->effective_from->toDateString(),
                'content' => $record->content,
                'required_at_purchase' => $record->required_at_purchase,
                'required_at_registration' => $record->required_at_registration,
            ])
            ->schema(fn (Document $record): array => $this->versionSchema($record, withSource: false))
            ->action(function (Document $record, array $data): void {
                // ⚠️ Od dnia wejścia w życie formularz przysyła wyłącznie flagi (reszta pól jest
                // wyłączona), a gdyby przysłał więcej — odrzuci to model po dacie z bazy.
                $record->update(array_intersect_key($data, array_flip([
                    'title', 'content', 'effective_from', 'required_at_purchase', 'required_at_registration',
                ])));

                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    private function deleteVersionAction(): Action
    {
        return Action::make('deleteVersion')
            ->label(__('Delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            // Usunąć wolno wyłącznie wersję zaplanowaną — nikt jej jeszcze nie zaakceptował.
            ->visible(fn (Document $record): bool => $this->canManageDocuments()
                && $this->documents()->statusOf($record) === DocumentStatus::Scheduled)
            ->authorize(fn (): bool => $this->canManageDocuments())
            ->action(function (Document $record): void {
                $record->delete();

                Notification::make()->success()->title(__('Deleted'))->send();
            });
    }

    /**
     * Przycisk „Podgląd szablonu" — otwiera się w NOWEJ KARCIE, żeby nie zabierać miejsca edytorowi
     * i nie zmieniać edytowanej treści. Bez „kopiuj do schowka": zaznaczenie i Ctrl+C.
     */
    private function previewTemplateAction(string $name): Action
    {
        return Action::make($name)
            ->label(__('Template preview'))
            ->icon('heroicon-m-eye')
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('viewAny', DocumentTemplate::class))
            ->url(fn (): string => FisheryResource::getUrl('document-template-preview', [
                'record' => $this->fishery(),
                'type' => $this->activeType()->value,
            ]))
            ->openUrlInNewTab();
    }

    /**
     * @return array<int, mixed>
     */
    private function versionSchema(?Document $record, bool $withSource): array
    {
        $locked = $record instanceof Document && FisheryDocuments::isLocked($record);
        $fishery = $this->fishery();

        $fields = [];

        if ($withSource) {
            $fields[] = Select::make('source')
                ->label(__('Start from'))
                ->helperText(__('A copy of an existing version or any template — the choice is not checked against the document type.'))
                ->options(fn (Get $get): array => $this->sourceOptions(DocumentType::tryFrom((string) $get('type')) ?? $this->activeType()))
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    foreach ($this->sourceContent($state) as $field => $value) {
                        $set($field, $value);
                    }
                });
        }

        return [
            ...$fields,
            Select::make('type')
                ->label(__('Document'))
                ->options(DocumentType::options())
                ->required()
                ->live()
                ->disabled($record instanceof Document),
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
                // ⚠️ Wyraźny komunikat obok daty: od tego dnia edycja nie będzie możliwa.
                ->helperText(__('From this date the version is in force and can no longer be edited or deleted. It takes effect at midnight in the fishery time zone.'))
                ->disabled($locked)
                // ⚠️ Filament waliduje także pole WYŁĄCZONE — przy wersji obowiązującej reguły daty
                // odrzuciłyby sam zapis flag. Datę wersji obowiązującej chroni model, nie ta reguła.
                ->rules($locked ? [] : [
                    fn (): DocumentEffectiveDateIsAhead => new DocumentEffectiveDateIsAhead($fishery),
                    fn (Get $get): DocumentEffectiveDateIsFree => new DocumentEffectiveDateIsFree(
                        (int) $fishery->getKey(),
                        $record instanceof Document ? $record->type : $get('type'),
                        $record?->getKey() === null ? null : (int) $record->getKey(),
                    ),
                ]),
            // ⚠️ Flagi wymagalności są edytowalne ZAWSZE, także w wersji obowiązującej.
            Toggle::make('required_at_purchase')
                ->label(__('Required at purchase')),
            Toggle::make('required_at_registration')
                ->label(__('Required at registration with the fishery'))
                ->helperText(__('Working meaning: the angler\'s first contact with the fishery. It will be settled when the angler portal is built.')),
            Actions::make([$this->previewTemplateAction('previewTemplateFromForm')])
                ->key('document_version_actions'),
            RichEditor::make('content')
                ->label(__('Content'))
                ->toolbarButtons(SharedFormComponents::getRichEditorOptions())
                ->required()
                ->disabled($locked)
                ->columnSpanFull(),
        ];
    }

    /**
     * Źródła nowej wersji: wersje tego łowiska i szablony — najpierw szablony pasującego rodzaju.
     *
     * @return array<string, array<string, string>>
     */
    private function sourceOptions(DocumentType $type): array
    {
        $versions = [];

        // ⚠️ `statusOf()` sięga po `$document->fishery` (strefa łowiska) — bez wstrzyknięcia
        // relacji każda wersja dociągałaby łowisko osobnym zapytaniem, przy każdym renderze modalu.
        foreach ($this->fishery()->documents()->where('type', $type->value)->orderByDesc('effective_from')->get() as $document) {
            $document->setRelation('fishery', $this->fishery());
            $versions['copy:'.$document->getKey()] = $document->title.' · '.$document->effective_from->format('d.m.Y')
                .' · '.$this->documents()->statusOf($document)->label();
        }

        $templates = [];

        if (Gate::allows('viewAny', DocumentTemplate::class)) {
            $all = DocumentTemplate::query()->orderBy('name')->get()
                ->sortBy(fn (DocumentTemplate $template): int => $template->type === $type ? 0 : 1);

            foreach ($all as $template) {
                $templates['template:'.$template->getKey()] = $template->name.' ('.$template->type->label().')';
            }
        }

        return array_filter([
            __('Copy of a version') => $versions,
            __('Templates') => $templates,
        ]);
    }

    /**
     * Treść wybranego źródła — kopia, nie odwołanie. ⚠️ Wersję do skopiowania szukamy WYŁĄCZNIE
     * w dokumentach tego łowiska: identyfikator przychodzi od klienta.
     *
     * @return array<string, mixed>
     */
    private function sourceContent(?string $source): array
    {
        [$kind, $id] = array_pad(explode(':', (string) $source, 2), 2, null);

        if (! is_numeric($id)) {
            return [];
        }

        if ($kind === 'copy') {
            $document = $this->fishery()->documents()->whereKey((int) $id)->first();

            return $document instanceof Document ? [
                'title' => $document->title,
                'content' => $document->content,
                // Kopia przenosi flagi wymagalności.
                'required_at_purchase' => $document->required_at_purchase,
                'required_at_registration' => $document->required_at_registration,
            ] : [];
        }

        if ($kind === 'template' && Gate::allows('viewAny', DocumentTemplate::class)) {
            $template = DocumentTemplate::query()->find((int) $id);

            return $template instanceof DocumentTemplate ? [
                'title' => $template->name,
                'content' => $template->content,
            ] : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsFor(DocumentType $type, ?string $source): array
    {
        return [
            'source' => $source,
            'type' => $type->value,
            'title' => null,
            'content' => null,
            'required_at_purchase' => $type === DocumentType::Terms,
            'required_at_registration' => false,
            ...$this->sourceContent($source),
            // Domyślna data nowej i skopiowanej wersji: dziś + 14 dni w strefie łowiska.
            'effective_from' => FisheryDocuments::defaultEffectiveFrom($this->fishery())->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createVersion(array $data): void
    {
        abort_unless($this->canManageDocuments(), 403);

        $type = DocumentType::tryFrom((string) ($data['type'] ?? '')) ?? $this->activeType();

        $this->fishery()->documents()->create([
            'type' => $type->value,
            'title' => $data['title'],
            'effective_from' => $data['effective_from'],
            'content' => $data['content'],
            'required_at_purchase' => (bool) ($data['required_at_purchase'] ?? false),
            'required_at_registration' => (bool) ($data['required_at_registration'] ?? false),
        ]);

        Notification::make()->success()->title(__('The new version was saved'))->send();
    }

    private function activeType(): DocumentType
    {
        return DocumentType::tryFrom((string) $this->activeTab) ?? DocumentType::Terms;
    }

    private function canManageDocuments(): bool
    {
        return Gate::allows('update', $this->fishery());
    }

    private function documents(): FisheryDocuments
    {
        return $this->documents ??= new FisheryDocuments($this->fishery());
    }

    private function fishery(): Fishery
    {
        /** @var Fishery $fishery */
        $fishery = $this->getOwnerRecord();

        return $fishery;
    }
}
