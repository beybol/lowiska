<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\DocumentType;
use App\Filament\Resources\FisheryResource;
use App\Models\DocumentTemplate;
use App\Models\Fishery;
use App\Services\FisheryNavigation;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Podgląd szablonu dokumentu — TYLKO DO ODCZYTU, w nowej karcie (zadanie 021).
 *
 * ⚠️ Strona niczego nie zapisuje i nie dotyka edytowanej wersji: otwiera się obok edytora, żeby
 * operator mógł zaznaczyć fragment i skopiować go Ctrl+C. Nie ma jej w sub-nawigacji.
 *
 * ⚠️ Dostęp **jawnie**: łowisko przez `FisheryPolicy::view()`, szablony przez
 * `DocumentTemplatePolicy::viewAny()` — właściciel ma do nich wyłącznie odczyt.
 *
 * ⚠️ Treść szablonu wychodzi przez `Str::sanitizeHtml()` — to HTML z edytora.
 */
class PreviewDocumentTemplate extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FisheryResource::class;

    protected string $view = 'filament.resources.fishery-resource.pages.preview-document-template';

    /** Wybrany szablon — stan w adresie; to dane od klienta, więc rozwiązywany przez `find()`. */
    public ?int $template = null;

    /** Rodzaj dokumentu, od którego zaczyna lista (szablony pasujące na górze). */
    public ?string $type = null;

    /**
     * @var array<int, string>
     */
    protected $queryString = ['template', 'type'];

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->getRecord()) ?? false, 404);
        abort_unless(Gate::allows('viewAny', DocumentTemplate::class), 403);

        if ($this->template === null) {
            $this->template = $this->templates()[0]?->getKey();
        }
    }

    public function getTitle(): string
    {
        return __('Template preview');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Template preview'));
    }

    /**
     * Wszystkie szablony — najpierw pasujące do rodzaju, potem pozostałe (typ podpowiada, nie ogranicza).
     *
     * @return array<int, DocumentTemplate|null>
     */
    public function templates(): array
    {
        $type = DocumentType::tryFrom((string) $this->type);

        $templates = DocumentTemplate::query()->orderBy('name')->get()
            ->sortBy(fn (DocumentTemplate $template): int => $template->type === $type ? 0 : 1)
            ->values()
            ->all();

        return $templates === [] ? [null] : $templates;
    }

    public function selected(): ?DocumentTemplate
    {
        return $this->template === null ? null : DocumentTemplate::query()->find($this->template);
    }

    public function sanitizedContent(): HtmlString
    {
        return new HtmlString(Str::sanitizeHtml((string) $this->selected()?->content));
    }

    private function fishery(): Fishery
    {
        /** @var Fishery $fishery */
        $fishery = $this->getRecord();

        return $fishery;
    }
}
