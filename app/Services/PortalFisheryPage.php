<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PositionAttributeType;
use App\Models\Fish;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\PositionAttributeValue;
use App\Models\PositionGroup;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Dane strony łowiska w portalu — obie zakładki i box z ceną (zadanie 032, portal-v3 §1).
 *
 * ⚠️ **Klasa NIE liczy ceny ani sprzedawalności.** „Cena od" pochodzi z `PriceFrom` (cennik),
 * reszta to odczyt konfiguracji łowiska. Kalendarz z ceną pobytu dochodzi w 033 przez `StayOffer`.
 *
 * ⚠️ **Puste pole się nie pokazuje, a trzeci stan („nie podano") nie jest „nie"** — każda pozycja
 * listy parametrów istnieje tylko przy wartości wypowiedzianej przez łowisko (`strona-publiczna.md` §1).
 *
 * ⚠️ Treści z edytora (opis, dojazd, rekordy) wychodzą przez `Str::sanitizeHtml()` — to HTML operatora.
 */
final class PortalFisheryPage
{
    /** „Cena od" — box stoi w każdej z czterech zakładek; liczona RAZ na stronę (038). */
    private ?string $priceFrom = null;

    private bool $priceFromResolved = false;

    private ?FisheryRulesSummary $rules = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * Wyciąg zasad współdzielony przez kalendarz, Cennik i stronę — jedna instancja, więc pozycje liczą się raz.
     */
    public function rules(): FisheryRulesSummary
    {
        return $this->rules ??= new FisheryRulesSummary($this->fishery);
    }

    /**
     * Łowisko z kompletem relacji potrzebnych stronie — jedno wczytanie, bez N+1 na stanowiskach.
     */
    public static function load(Fishery $fishery): Fishery
    {
        return $fishery->load([
            'state',
            'company',
            'currency',
            'fisheryTypes',
            'fish',
            'dominantFish',
            'conveniences',
            'fishingMethods',
            'positions' => fn ($query) => $query->available()->with([
                'groups',
                'attributeValues.attribute',
                'attributeValues.option',
            ]),
            'positionGroups',
            // Zdjęcia galerii i mapa — jedno zapytanie, kolejność z `order_column` (036).
            'media',
            // Okresy sprzedaży — czytają je kotwica kalendarza, „cena od", sezon w wyciągu i koniec ostatniego
            // sezonu; bez tego każdy z nich pyta bazę osobno (038). Wczytane BEZ ograniczeń — patrz
            // `strona-publiczna.md` §6.
            'salePeriods',
        ]);
    }

    public function priceFrom(): ?string
    {
        if (! $this->priceFromResolved) {
            $this->priceFrom = PortalFisheries::priceFrom($this->fishery);
            $this->priceFromResolved = true;
        }

        return $this->priceFrom;
    }

    /** „Jezioro rynnowe 16 ha · wielkopolskie · prowadzi: …" — w kolejności z makiety. */
    public function subtitle(): string
    {
        // Tylko akwen i województwo — budowanie całej karty liczyło przy okazji „cenę od", zasady i okładkę.
        return implode(' · ', array_filter([
            PortalFisheries::water($this->fishery),
            PortalFisheries::stateName($this->fishery),
            filled($this->fishery->company?->name) ? __('run by :company', ['company' => $this->fishery->company->name]) : null,
        ]));
    }

    /**
     * Zdjęcia galerii w kolejności z panelu — pierwsze jest okładką (zadanie 036, R1). Zdjęcie bez żadnego
     * wariantu się nie pokazuje (oryginału nie podstawiamy, ADR-023).
     *
     * `alt` generowany: „{nazwa łowiska} — zdjęcie N" (R4).
     *
     * @return list<array{src: string, srcset: string, full: string, width: int, height: int, alt: string}>
     */
    public function photos(): array
    {
        $photos = [];

        foreach ($this->fishery->getMedia(FisheryImages::GALLERY) as $media) {
            $image = $this->image($media, __(':fishery — photo :number', [
                'fishery' => $this->fishery->name,
                'number' => count($photos) + 1,
            ]));

            if ($image !== null) {
                $photos[] = $image;
            }
        }

        return $photos;
    }

    /**
     * Mapa łowiska — warianty jak w galerii, ten sam podgląd pełnoekranowy (036).
     *
     * @return array{src: string, srcset: string, full: string, width: int, height: int, alt: string}|null
     */
    public function map(): ?array
    {
        $media = $this->fishery->getFirstMedia(FisheryImages::MAP);

        return $media === null
            ? null
            : $this->image($media, __('Fishery map — :fishery', ['fishery' => $this->fishery->name]));
    }

    /**
     * Zakładka „Cennik" (zadanie 034) — składowe ceny z `PortalPriceList`, bez przeliczania.
     *
     * @return array{rates: list<array<string, mixed>>, surcharges: list<array<string, mixed>>, presale: string|null, services: list<array<string, mixed>>, empty: bool}
     */
    public function pricing(): array
    {
        $list = new PortalPriceList($this->fishery, $this->rules());
        $rates = $list->rates();
        $surcharges = $list->surcharges();
        $services = $list->services();

        return [
            'rates' => $rates,
            'surcharges' => $surcharges,
            'presale' => $list->presale(),
            'services' => $services,
            'empty' => $rates === [] && $surcharges === [] && $services === [],
        ];
    }

    /**
     * Zakładka „Dokumenty" (zadanie 034): dla każdego rodzaju WYŁĄCZNIE wersja obowiązująca dziś
     * (`FisheryDocuments::current()`, ADR-017), w kolejności rodzajów. Rodzaj bez takiej wersji nie
     * ma sekcji; wersji zaplanowanych i archiwalnych nie pokazujemy (R1, R7).
     *
     * ⚠️ Treść z edytora operatora — wychodzi przez `html()` (sanityzacja).
     *
     * @return list<array{type: string, title: string, effective_from: string, content: HtmlString|null}>
     */
    public function documents(): array
    {
        $documents = new FisheryDocuments($this->fishery);
        $sections = [];

        foreach (DocumentType::cases() as $type) {
            $current = $documents->current($type);

            if ($current === null) {
                continue;
            }

            $sections[] = [
                'type' => $type->label(),
                'title' => (string) $current->title,
                'effective_from' => $current->effective_from->format('d.m.Y'),
                'content' => $this->html($current->content),
            ];
        }

        return $sections;
    }

    public function html(?string $content): ?HtmlString
    {
        $clean = Str::sanitizeHtml((string) $content);

        return blank(strip_tags($clean)) ? null : new HtmlString($clean);
    }

    /** Opis do `<meta name="description">` — sam tekst, najwyżej 160 znaków. */
    public function metaDescription(): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $this->fishery->description)) ?? '');

        return $text !== ''
            ? Str::limit($text, 157)
            : __(':fishery — fishery rules, prices and dates.', ['fishery' => $this->fishery->name]);
    }

    /** Numer do `tel:` — cyfry i plus. */
    public function phoneHref(): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) $this->fishery->phone);

        return $digits === '' ? null : 'tel:'.$digits;
    }

    /**
     * Sekcja „Akwen" — wyłącznie wypełnione pozycje.
     *
     * @return array<string, string>
     */
    public function waterParameters(): array
    {
        $fish = $this->fishery->fish
            ->sortBy(fn (Fish $fish): int => $fish->id === $this->fishery->dominant_fish_id ? 0 : 1)
            ->map(fn (Fish $fish): string => $fish->id === $this->fishery->dominant_fish_id
                ? __(':fish (dominant)', ['fish' => __($fish->name)])
                : __($fish->name))
            ->values()
            ->all();

        if ($fish === [] && $this->fishery->dominantFish !== null) {
            $fish = [__(':fish (dominant)', ['fish' => __($this->fishery->dominantFish->name)])];
        }

        $day = filled($this->fishery->day_start_time) && filled($this->fishery->day_end_time)
            ? substr((string) $this->fishery->day_start_time, 0, 5).'–'.substr((string) $this->fishery->day_end_time, 0, 5)
            : null;

        return array_filter([
            __('Area') => (float) $this->fishery->area > 0
                ? Number::format((float) $this->fishery->area, maxPrecision: 2, locale: app()->getLocale()).' ha'
                : null,
            __('Positions') => $this->fishery->positions->count() > 0 ? (string) $this->fishery->positions->count() : null,
            __('Fishing day') => $day,
            __('Average depth') => filled($this->fishery->avg_depth) ? $this->metres($this->fishery->avg_depth) : null,
            __('Maximum depth') => filled($this->fishery->max_depth) ? $this->metres($this->fishery->max_depth) : null,
            __('Fish') => $fish !== [] ? implode(', ', $fish) : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * „Zanim przyjedziesz" — parametry wędkarza z 021. ⚠️ `null` = „nie podano" i NIE daje pozycji.
     *
     * @return array<string, string>
     */
    public function anglerRules(): array
    {
        $fishery = $this->fishery;

        return array_filter([
            __('Fishing licence') => match ($fishery->fishing_license_required) {
                // Osobny klucz: `required` tłumaczy się już jako „obowiązkowa" (usługi).
                true => __('needed'),
                false => __('not required'),
                null => null,
            },
            __('Rods included in the price') => $fishery->rods_included !== null ? (string) $fishery->rods_included : null,
            __('Fish') => match ($fishery->no_kill) {
                true => __('released (no-kill)'),
                false => __('can be taken'),
                null => null,
            },
            __('Campfires') => match ($fishery->campfires_banned) {
                true => __('banned'),
                false => __('allowed'),
                null => null,
            },
        ], fn (?string $value): bool => $value !== null);
    }

    /** @return list<string> */
    public function fishingMethods(): array
    {
        return $this->fishery->fishingMethods->map(fn ($method): string => __($method->name))->values()->all();
    }

    /** @return list<string> */
    public function conveniences(): array
    {
        return $this->fishery->conveniences->map(fn ($convenience): string => __($convenience->name))->values()->all();
    }

    /**
     * Stanowiska w sprzedaży jako płaska lista **w kolejności etykiet** (porządek naturalny: 2, 8, 15),
     * z grupami, pojemnością i cechami filtrowalnymi — w kolumnach tabeli „Stanowiska" (zadanie 038, R4).
     *
     * Pusta wartość to `''` (grupy, cechy) albo `null` (pojemność „nie podano") — widok chowa kolumnę,
     * w której żadne stanowisko nie ma wartości.
     *
     * @return list<array{label: string, groups: string, capacity: int|null, features: string}>
     */
    public function positions(): array
    {
        return $this->fishery->positions
            ->sort(fn (Position $a, Position $b): int => strnatcasecmp($a->name, $b->name))
            ->map(fn (Position $position): array => [
                'label' => $position->name,
                'groups' => $position->groups->pluck('name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->implode(', '),
                'capacity' => $position->max_anglers !== null ? (int) $position->max_anglers : null,
                'features' => implode(', ', $this->filterableFeatures($position)),
            ])
            ->values()
            ->all();
    }

    /**
     * Grupy stanowisk pod listą stanowisk (makieta „Szczegóły"): nazwa, **stanowiska w sprzedaży
     * należące do grupy** (porządek naturalny etykiet) i opis grupy, jeśli jest. Grupa bez stanowiska
     * w sprzedaży się nie pokazuje.
     *
     * ⚠️ Opis grupy pochodzi z EDYTORA (HTML), tak jak opis łowiska — wychodzi przez `html()`
     * (sanityzacja), nigdy jako tekst: wypisany przez `{{ }}` pokazywał znaczniki `<p>` na ekranie.
     *
     * @return list<array{name: string, positions: string, description: HtmlString|null}>
     */
    public function groups(): array
    {
        $labels = [];

        foreach ($this->fishery->positions as $position) {
            foreach ($position->groups as $group) {
                $labels[$group->id][] = $position->name;
            }
        }

        $groups = [];

        foreach ($this->fishery->positionGroups->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $group) {
            /** @var PositionGroup $group */
            if (! isset($labels[$group->id])) {
                continue;
            }

            $names = $labels[$group->id];
            usort($names, 'strnatcasecmp');

            $groups[] = [
                'name' => $group->name,
                'positions' => trans_choice('pos. :labels|pos. :labels', count($names), ['labels' => implode(', ', $names)]),
                'description' => $this->html($group->description),
            ];
        }

        return $groups;
    }

    /**
     * Krótki adres wprost pod domeną (ADR-021, opcja B) — do wypisania, bez schematu.
     * Z adresu aplikacji, nie z samego hosta: lokalnie port jest częścią adresu (`localhost:11000`).
     */
    public function shortAddress(): string
    {
        return self::displayUrl(url($this->fishery->slug));
    }

    /** Adres do wypisania w linku zewnętrznym — bez schematu i końcowego ukośnika. */
    public static function displayUrl(string $url): string
    {
        return rtrim((string) preg_replace('#^https?://(www\.)?#i', '', $url), '/');
    }

    /**
     * Cechy filtrowalne stanowiska z wypowiedzianą wartością. ⚠️ Brak wiersza wartości to TRZECI stan
     * — nie pokazujemy go; flaga „nie" też się nie pokazuje, bo lista mówi, co stanowisko MA.
     *
     * @return list<string>
     */
    private function filterableFeatures(Position $position): array
    {
        return $position->attributeValues
            ->filter(fn (PositionAttributeValue $value): bool => $value->attribute !== null && $value->attribute->is_filterable)
            ->map(function (PositionAttributeValue $value): ?string {
                $attribute = $value->attribute;

                return match ($attribute->type) {
                    PositionAttributeType::Flag => $value->value_flag ? Str::lower(__($attribute->name)) : null,
                    PositionAttributeType::Number => $value->value_number !== null
                        ? trim(__($attribute->name).' '.$value->value_number.' '.(string) $attribute->unit)
                        : null,
                    PositionAttributeType::Choice => $value->option !== null
                        ? __($attribute->name).': '.__($value->option->name)
                        : null,
                };
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{src: string, srcset: string, full: string, width: int, height: int, alt: string}|null
     */
    private function image(Media $media, string $alt): ?array
    {
        $largest = FisheryImages::largest($media);

        if ($largest === null) {
            return null;
        }

        return [
            'src' => (string) FisheryImages::url($media, 960),
            'srcset' => FisheryImages::srcset($media),
            'full' => $largest['url'],
            'width' => $largest['width'],
            'height' => $largest['height'],
            'alt' => $alt,
        ];
    }

    private function metres(mixed $value): string
    {
        return Number::format((float) $value, maxPrecision: 1, locale: app()->getLocale()).' m';
    }
}
