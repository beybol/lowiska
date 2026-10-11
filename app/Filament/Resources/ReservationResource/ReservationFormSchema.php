<?php

namespace App\Filament\Resources\ReservationResource;

use App\Enums\AnglerLinkStatus;
use App\Enums\TermsExceptionKind;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Services\AmountFormatter;
use App\Services\FisheryAccess;
use App\Services\FishingDayCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

/**
 * JEDNA metoda budująca pola formularza rezerwacji (zadanie 047, C1) — dla pełnej strony C
 * (`CreateReservation`), pełnej edycji (`EditReservation`), krótkiego formularza w kalendarzu
 * i szybkiej edycji. Dwa kształty (`full()`, `quick()`), jedna interpretacja pól.
 *
 * ⚠️ **Łowisko pól reaktywnych nie pochodzi z pola `Hidden`** (047, D1). Formularz C pyta
 * `FisheryAccess::findFishery()` (domyślnie zawężone do łowisk użytkownika), edycja i kalendarz podają
 * łowisko z rekordu po stronie serwera. Podmieniony `fishery_id` daje puste opcje, a nie cudze dane.
 *
 * ⚠️ Pole powodu wyjątku od reguł pojawia się WYŁĄCZNIE przy odmowie oferty, razem z listą naruszonych
 * reguł (B1); pole powodu wyjątku usługi — wyłącznie przy usłudze niedostępnej na stanowisku (B6).
 */
final class ReservationFormSchema
{
    /**
     * Pełny formularz: C (bez rekordu) albo pełna edycja (z rekordem).
     *
     * @return array<int, mixed>
     */
    public static function full(?Reservation $record = null): array
    {
        $fishery = $record !== null
            ? fn (): ?Fishery => $record->fishery
            : fn (Get $get, string $prefix = ''): ?Fishery => self::verifiedFishery($get($prefix.'fishery_id'));

        return [
            Hidden::make('fishery_id')->default(fn (): mixed => request()->get('fishery')),
            Grid::make(['default' => 1, 'lg' => 3])
                ->schema([
                    self::anglerSection($fishery, $record)->columnSpan(1),
                    self::staySection($fishery)->columnSpan(1),
                    self::offerSection($fishery, $record)->columnSpan(1),
                ]),
        ];
    }

    /**
     * Krótki formularz kalendarza („Zarezerwuj…") i szybka edycja — stanowisko i przyjazd ustalone.
     *
     * @param  Closure(): Fishery  $fishery  łowisko z rekordu strony / rezerwacji (po stronie serwera)
     * @return array<int, mixed>
     */
    public static function quick(Closure $fishery, ?Reservation $record = null, int $maxNights = 365): array
    {
        $editable = $record === null || self::contactEditable($record);

        return [
            Hidden::make('position_id'),
            Hidden::make('starts_on'),
            TextInput::make('angler_name')->label(__('Name'))->required()->maxLength(255)->disabled(! $editable),
            Grid::make(2)->schema([
                TextInput::make('angler_phone')->label(__('Phone'))->tel()->maxLength(32)->disabled(! $editable),
                TextInput::make('angler_email')->label(__('Email'))->email()->maxLength(255)->disabled(! $editable),
            ]),
            Grid::make(3)->schema([
                TextInput::make('nights')->label(__('Nights'))->integer()->required()->minValue(1)->maxValue(max(1, $maxNights))->live(debounce: 500),
                self::stayPeriod($fishery)->columnSpan(2),
            ]),
            Grid::make(2)->schema([
                TextInput::make('anglers')->label(__('Anglers'))->integer()->required()->minValue(1)->maxValue(50)->live(debounce: 500),
                TextInput::make('companions')->label(__('Companions'))->integer()->minValue(0)->maxValue(50)->live(debounce: 500),
            ]),
            ...self::offerFields($fishery, $record),
        ];
    }

    /**
     * Stan pełnego formularza z rezerwacji.
     *
     * @return array<string, mixed>
     */
    public static function fullState(Reservation $record): array
    {
        return array_merge(self::quickState($record), [
            'fishery_id' => $record->fishery_id,
            'angler_notes' => $record->angler?->notes,
            'services' => collect($record->terms->services ?? [])
                ->map(fn (array $line): array => ['service_id' => (int) $line['service_id'], 'quantity' => (int) $line['quantity']])
                ->values()
                ->all(),
            'internal_note' => $record->internal_note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function quickState(Reservation $record): array
    {
        $record->loadMissing(['angler.user', 'terms']);
        $agreed = $record->terms?->agreed_total_cents;
        $contact = $record->angler?->effectiveContact() ?? [];

        return [
            'position_id' => $record->position_id,
            'starts_on' => $record->first_night_on->toDateString(),
            'nights' => $record->nights,
            'anglers' => $record->terms->anglers ?? 1,
            'companions' => $record->terms->companions ?? 0,
            'angler_name' => $contact['name'] ?? null,
            'angler_phone' => $contact['phone'] ?? null,
            'angler_email' => $contact['email'] ?? null,
            'agreed' => $agreed !== null,
            'agreed_total' => $agreed === null ? null : number_format($agreed / 100, 2, '.', ''),
        ];
    }

    /** Dane kontaktowe od operatora poprawiają wyłącznie relację niepowiązaną (ADR-024). */
    public static function contactEditable(Reservation $record): bool
    {
        return in_array($record->angler?->status, [AnglerLinkStatus::Unlinked, AnglerLinkStatus::Pending], true);
    }

    /**
     * Czy zmiana dotyka czegoś, co kształtuje cenę i warunki (047, C2): stanowisko, termin, skład, usługi,
     * cena uzgodniona. Sam kontakt i notatka — nie.
     *
     * @param  array<string, mixed>  $data
     */
    public static function priceDriversChanged(Reservation $record, array $data): bool
    {
        $record->loadMissing('terms');
        $terms = $record->terms;

        $changed = (int) ($data['position_id'] ?? $record->position_id) !== (int) $record->position_id
            || (string) ($data['starts_on'] ?? $record->first_night_on->toDateString()) !== $record->first_night_on->toDateString()
            || (int) ($data['nights'] ?? $record->nights) !== (int) $record->nights
            || (int) ($data['anglers'] ?? $terms?->anglers) !== (int) $terms?->anglers
            || (int) ($data['companions'] ?? $terms?->companions) !== (int) $terms?->companions;

        if (array_key_exists('services', $data)) {
            $before = collect($terms->services ?? [])->mapWithKeys(fn (array $line): array => [(int) $line['service_id'] => (int) $line['quantity']])->sortKeys()->all();
            $after = ReservationFormSubmission::services((array) $data['services']);
            ksort($after);
            $changed = $changed || $before !== $after;
        }

        if (array_key_exists('agreed', $data)) {
            $agreed = ! empty($data['agreed']) ? ReservationActions::toCents($data['agreed_total'] ?? null) : null;
            $changed = $changed || $agreed !== $terms?->agreed_total_cents;
        }

        return $changed;
    }

    /**
     * @param  Closure  $fishery  `fn (Get $get, string $prefix = ''): ?Fishery`
     */
    private static function anglerSection(Closure $fishery, ?Reservation $record): Section
    {
        $editable = $record === null || self::contactEditable($record);
        $fromList = fn (Get $get): bool => $record === null && filled($get('fishery_angler_id'));

        $fields = [];

        if ($record === null) {
            $fields[] = Select::make('fishery_angler_id')
                ->label(__('Angler of this fishery'))
                ->helperText(__('Pick a returning angler or fill in the fields below.'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search, Get $get): array => self::anglerOptions($fishery($get), $search))
                ->getOptionLabelUsing(fn ($value, Get $get): ?string => self::anglerOptions($fishery($get), null, (int) $value)[(int) $value] ?? null)
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($fishery): void {
                    $angler = ReservationFormSubmission::findAngler($fishery($get), $state);
                    $set('angler_name', $angler?->effectiveContact()['name']);
                    $set('angler_phone', $angler?->effectiveContact()['phone']);
                    $set('angler_email', $angler?->effectiveContact()['email']);
                });
        }

        $fields[] = TextInput::make('angler_name')->label(__('Name'))->required()->maxLength(255)
            ->disabled(fn (Get $get): bool => ! $editable || $fromList($get));
        $fields[] = TextInput::make('angler_phone')->label(__('Phone'))->tel()->maxLength(32)
            ->disabled(fn (Get $get): bool => ! $editable || $fromList($get));
        $fields[] = TextInput::make('angler_email')->label(__('Email'))->email()->maxLength(255)
            ->helperText(__('With an email address the angler gets the reservation and can confirm it.'))
            ->disabled(fn (Get $get): bool => ! $editable || $fromList($get));

        if ($record === null) {
            $fields[] = Textarea::make('angler_notes')->label(__('Note about the angler'))->rows(2);
        }

        return Section::make(__('Angler'))
            ->description($editable ? null : __('The angler manages their own contact details in their account.'))
            ->schema($fields);
    }

    /**
     * @param  Closure  $fishery  `fn (Get $get, string $prefix = ''): ?Fishery`
     */
    private static function staySection(Closure $fishery): Section
    {
        return Section::make(__('Stay'))
            ->schema([
                Select::make('position_id')
                    ->label(__('Position'))
                    ->options(fn (Get $get): array => $fishery($get)?->positions()->orderBy('name')->pluck('name', 'id')->all() ?? [])
                    ->required()
                    ->live(),
                // Jedno pod drugim (047, B4): długa etykieta przyjazdu nie rozjeżdża pól.
                DatePicker::make('starts_on')->label(__('Arrival (first night)'))->required()->live(),
                Grid::make(3)->schema([
                    TextInput::make('nights')->label(__('Nights'))->integer()->minValue(1)->maxValue(365)->default(1)->required()->live(debounce: 500),
                    self::stayPeriod($fishery)->columnSpan(2),
                ]),
                Grid::make(2)->schema([
                    TextInput::make('anglers')->label(__('Anglers'))->integer()->minValue(1)->maxValue(50)->default(1)->required()->live(debounce: 500),
                    TextInput::make('companions')->label(__('Companions'))->integer()->minValue(0)->maxValue(50)->default(0)->live(debounce: 500),
                ]),
                Repeater::make('services')
                    ->label(__('Additional services'))
                    ->schema([
                        Select::make('service_id')
                            ->label(__('Service'))
                            ->options(fn (Get $get): array => self::serviceOptions($fishery($get, '../../')))
                            ->required()
                            ->live(),
                        TextInput::make('quantity')->label(__('Quantity'))->integer()->minValue(1)->maxValue(99)->default(1)->required()->live(debounce: 500),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->live(),
            ]);
    }

    /**
     * Przyjazd i wyjazd (data i godzina) obok liczby dób, przeliczane na żywo z doby łowieckiej łowiska.
     *
     * @param  Closure  $fishery  `fn (Get $get, string $prefix = ''): ?Fishery`
     */
    private static function stayPeriod(Closure $fishery): Text
    {
        return Text::make(function (Get $get) use ($fishery): HtmlString {
            $resolved = $fishery($get);
            $nights = is_numeric($get('nights')) ? (int) $get('nights') : 0;
            $startsOn = is_string($get('starts_on')) ? substr($get('starts_on'), 0, 10) : '';

            if ($resolved === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) !== 1 || $nights < 1 || $nights > 365) {
                return new HtmlString('');
            }

            // Arytmetyka na samych datach (bez strefy) — strefę łowiska nakłada FishingDayCalendar.
            try {
                $lastNight = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn)?->addDays($nights - 1)->toDateString();
            } catch (\Throwable) {
                return new HtmlString('');
            }

            $calendar = new FishingDayCalendar($resolved);
            $arrival = $calendar->dayStartingOn($startsOn)?->startsAt;
            $departure = $lastNight === null ? null : $calendar->dayStartingOn($lastNight)?->endsAt;

            if ($arrival === null || $departure === null) {
                return new HtmlString('');
            }

            $timezone = $resolved->timezoneName();
            $locale = app()->getLocale();
            $format = fn (CarbonImmutable $moment): string => $moment->setTimezone($timezone)->locale($locale)->isoFormat('dd DD.MM.YYYY HH:mm');

            return new HtmlString('<div class="pt-7 text-sm leading-snug" data-stay-period>'
                .'<span class="text-gray-500">'.e(__('Arrival')).':</span> <strong>'.e($format($arrival)).'</strong><br>'
                .'<span class="text-gray-500">'.e(__('Departure')).':</span> <strong>'.e($format($departure)).'</strong></div>');
        });
    }

    /**
     * @param  Closure  $fishery  `fn (Get $get, string $prefix = ''): ?Fishery`
     */
    private static function offerSection(Closure $fishery, ?Reservation $record): Section
    {
        return Section::make(__('Offer'))
            ->schema([
                ...self::offerFields($fishery, $record),
                Textarea::make('internal_note')->label(__('Internal note'))->rows(2),
            ]);
    }

    /**
     * Cena z rozbiciem, cena uzgodniona, powody wyjątków i — przy edycji — wyjątki już zapisane.
     *
     * @return array<int, mixed>
     */
    private static function offerFields(Closure $fishery, ?Reservation $record): array
    {
        $quote = fn (Get $get): ?ReservationFormQuote => ($resolved = $fishery($get)) === null ? null : ReservationFormQuote::for($resolved, [
            'position_id' => $get('position_id'),
            'starts_on' => $get('starts_on'),
            'nights' => $get('nights'),
            'anglers' => $get('anglers'),
            'companions' => $get('companions'),
            'services' => $get('services') ?? self::servicesOf($record),
        ], $record);

        $fields = [
            Text::make(fn (Get $get): HtmlString => $quote($get)?->html()
                ?? new HtmlString(e(__('Pick a position, an arrival date and the number of nights to see the price.')))),
        ];

        if ($record !== null && ($exceptions = self::exceptionsHtml($record)) !== null) {
            $fields[] = Text::make($exceptions);
        }

        return array_merge($fields, [
            Toggle::make('agreed')->label(__('Agreed price'))->live(),
            TextInput::make('agreed_total')->label(__('Agreed total'))->numeric()->minValue(0)
                ->visible(fn (Get $get): bool => (bool) $get('agreed'))
                ->required(fn (Get $get): bool => (bool) $get('agreed')),
            TextInput::make('agreed_reason')->label(__('Reason for the agreed price'))->maxLength(500)
                ->visible(fn (Get $get): bool => (bool) $get('agreed'))
                // Ta sama kwota co dotąd nie wymaga ponownego powodu (047, C2).
                ->required(fn (Get $get): bool => (bool) $get('agreed')
                    && ReservationActions::toCents($get('agreed_total')) !== $record?->terms?->agreed_total_cents),
            TextInput::make('exception_reason')
                ->label(__('Reason to book against the sale rules'))
                ->helperText(__('The exception is recorded with your name.'))
                ->maxLength(500)
                ->visible(fn (Get $get): bool => (bool) $quote($get)?->refused())
                ->required(fn (Get $get): bool => (bool) $quote($get)?->refused() && ! self::hasException($record, [TermsExceptionKind::SaleRule, TermsExceptionKind::SaleBlocked])),
            TextInput::make('service_exception_reason')
                ->label(__('Reason to add an unavailable service'))
                ->helperText(__('The exception is recorded with your name.'))
                ->maxLength(500)
                ->visible(fn (Get $get): bool => (bool) $quote($get)?->needsServiceException())
                ->required(fn (Get $get): bool => (bool) $quote($get)?->needsServiceException() && ! self::hasException($record, [TermsExceptionKind::Service])),
            TextInput::make('change_reason')
                ->label(__('Reason for the change'))
                ->helperText(__('Needed when the term, position, party, services or price change.'))
                ->maxLength(500)
                ->visible(fn (Get $get): bool => $record !== null && self::priceDriversChanged($record, self::driverState($get)))
                ->required(fn (Get $get): bool => $record !== null && self::priceDriversChanged($record, self::driverState($get))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function driverState(Get $get): array
    {
        return array_filter([
            'position_id' => $get('position_id'),
            'starts_on' => $get('starts_on'),
            'nights' => $get('nights'),
            'anglers' => $get('anglers'),
            'companions' => $get('companions'),
            'services' => $get('services'),
            'agreed' => $get('agreed'),
            'agreed_total' => $get('agreed_total'),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Wyjątki już zapisane w warunkach — widoczne przy edycji i przenoszone przy zapisie (047, C2).
     */
    private static function exceptionsHtml(Reservation $record): ?HtmlString
    {
        $exceptions = $record->terms->exceptions ?? [];

        if ($exceptions === []) {
            return null;
        }

        $items = array_map(function (array $exception): string {
            $kind = TermsExceptionKind::tryFrom((string) ($exception['kind'] ?? ''));

            return '<li><strong>'.e($kind?->label() ?? (string) ($exception['kind'] ?? '')).'</strong>: '.e((string) ($exception['reason'] ?? '')).'</li>';
        }, $exceptions);

        return new HtmlString('<div class="text-sm" data-existing-exceptions><strong>'.e(__('Recorded exceptions')).'</strong><ul class="list-disc ps-5">'
            .implode('', $items).'</ul><span class="opacity-70">'.e(__('They stay with the reservation; a new reason is needed only for a new exception.')).'</span></div>');
    }

    /**
     * @param  array<int, TermsExceptionKind>  $kinds
     */
    private static function hasException(?Reservation $record, array $kinds): bool
    {
        $values = array_map(fn (TermsExceptionKind $kind): string => $kind->value, $kinds);

        return collect($record?->terms->exceptions ?? [])->contains(fn (array $exception): bool => in_array($exception['kind'] ?? null, $values, true));
    }

    /**
     * @return array<int, array{service_id: int, quantity: int}>
     */
    private static function servicesOf(?Reservation $record): array
    {
        return collect($record?->terms->services ?? [])
            ->map(fn (array $line): array => ['service_id' => (int) $line['service_id'], 'quantity' => (int) $line['quantity']])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function serviceOptions(?Fishery $fishery): array
    {
        if ($fishery === null) {
            return [];
        }

        $currency = $fishery->currency?->name;

        return AdditionalService::query()
            ->where('fishery_id', $fishery->getKey())
            ->isActive()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (AdditionalService $service): array => [(int) $service->getKey() => $service->name.' · '.$service->priceLabel($currency)])
            ->all();
    }

    /**
     * Podpowiedzi wyłącznie z relacji TEGO łowiska (ADR-024) — bez globalnej wyszukiwarki.
     *
     * @return array<int, string>
     */
    private static function anglerOptions(?Fishery $fishery, ?string $search, ?int $id = null): array
    {
        if ($fishery === null) {
            return [];
        }

        return FisheryAngler::query()
            ->with('user')
            ->where('fishery_id', $fishery->getKey())
            ->when($id !== null, fn ($query) => $query->whereKey($id))
            ->when(filled($search), fn ($query) => $query->where(fn ($inner) => $inner
                ->where('given_name', 'like', "%{$search}%")
                ->orWhere('given_phone', 'like', "%{$search}%")
                ->orWhere('given_email', 'like', "%{$search}%")))
            ->orderBy('given_name')
            ->limit(30)
            ->get()
            ->mapWithKeys(function (FisheryAngler $angler): array {
                $contact = $angler->effectiveContact();

                return [(int) $angler->getKey() => collect([$contact['name'], $contact['phone'], $contact['email']])->filter()->implode(' · ')];
            })
            ->all();
    }

    /** ⚠️ `fishery_id` z pola `Hidden` to dane od klienta — wyłącznie łowisko dostępne użytkownikowi. */
    public static function verifiedFishery(mixed $id): ?Fishery
    {
        return is_numeric($id) ? FisheryAccess::findFishery((int) $id) : null;
    }

    /** Kwota zamrożona w warunkach — do paska i popovera kalendarza. */
    public static function totalLabel(Reservation $record): ?string
    {
        return $record->terms === null ? null : AmountFormatter::cents((int) $record->terms->total_cents, $record->fishery?->currency?->name);
    }
}
