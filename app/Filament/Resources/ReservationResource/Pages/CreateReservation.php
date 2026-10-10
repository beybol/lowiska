<?php

namespace App\Filament\Resources\ReservationResource\Pages;

use App\Enums\ReservationSource;
use App\Filament\Resources\FisheryResource\Pages\ManageReservations;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\ReservationActions;
use App\Models\AdditionalService;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Position;
use App\Services\AmountFormatter;
use App\Services\AnglerDetails;
use App\Services\BookingRequest;
use App\Services\FisheryAccess;
use App\Services\FisheryNavigation;
use App\Services\ReservationBooking;
use App\Services\ReservationConfirmation;
use App\Services\StayOffer;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Formularz rezerwacji (ekran C) — PEŁNA strona z `Grid` + `Section` (zadanie 041).
 *
 * ⚠️ **Zapis idzie przez `ReservationBooking`, nie przez `Model::create()`** — `handleRecordCreation()`
 * woła serwis, a odmowa (zajęte, brak powodu wyjątku, limit usług) wraca jako komunikat i zostawia
 * formularz otwarty. Podgląd oferty pyta warstwę oferty (`StayOffer`), niczego nie liczy sam.
 *
 * Parametry z adresu (`position`, `from`, `nights`) wypełniają formularz — wejście z kalendarza (042).
 * Stanowisko z adresu przechodzi przez zawężenie do łowiska; obce id po prostu się nie wypełnia.
 */
class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    public function mount(): void
    {
        FisheryAccess::assertFisheryAccessOrAbort();
        parent::mount();

        $fisheryId = (int) request()->get('fishery');
        $position = request()->get('position');
        $prefill = array_filter([
            'position_id' => is_numeric($position) && Position::query()->where('fishery_id', $fisheryId)->whereKey($position)->exists()
                ? (int) $position
                : null,
            'starts_on' => self::validDate(request()->get('from')),
            'nights' => is_numeric(request()->get('nights')) ? max(1, min(365, (int) request()->get('nights'))) : null,
        ], fn ($value): bool => $value !== null);

        if ($prefill !== []) {
            $this->form->fill(array_merge($this->form->getRawState(), $prefill));
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Hidden::make('fishery_id')->default(fn (): mixed => request()->get('fishery')),
                Grid::make(['default' => 1, 'lg' => 3])
                    ->schema([
                        Section::make(__('Angler'))
                            ->schema([
                                Select::make('fishery_angler_id')
                                    ->label(__('Angler of this fishery'))
                                    ->helperText(__('Pick a returning angler or fill in the fields below.'))
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::anglerOptions($get('fishery_id'), $search))
                                    ->getOptionLabelUsing(fn ($value, Get $get): ?string => self::anglerOptions($get('fishery_id'), null, (int) $value)[(int) $value] ?? null)
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                        $angler = self::findAngler($get('fishery_id'), $state);
                                        $set('angler_name', $angler?->effectiveContact()['name']);
                                        $set('angler_phone', $angler?->effectiveContact()['phone']);
                                        $set('angler_email', $angler?->effectiveContact()['email']);
                                    }),
                                TextInput::make('angler_name')->label(__('Name'))->required()->maxLength(255)
                                    ->disabled(fn (Get $get): bool => filled($get('fishery_angler_id'))),
                                TextInput::make('angler_phone')->label(__('Phone'))->tel()->maxLength(32)
                                    ->disabled(fn (Get $get): bool => filled($get('fishery_angler_id'))),
                                TextInput::make('angler_email')->label(__('Email (optional)'))->email()->maxLength(255)
                                    ->helperText(__('With an email address the angler gets the reservation and can confirm it.'))
                                    ->disabled(fn (Get $get): bool => filled($get('fishery_angler_id'))),
                                Textarea::make('angler_notes')->label(__('Note about the angler'))->rows(2),
                            ])
                            ->columnSpan(1),
                        Section::make(__('Stay'))
                            ->schema([
                                Select::make('position_id')
                                    ->label(__('Position'))
                                    ->options(fn (Get $get): array => Position::query()
                                        ->where('fishery_id', $get('fishery_id'))
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all())
                                    ->required()
                                    ->live(),
                                Grid::make(2)->schema([
                                    DatePicker::make('starts_on')->label(__('Arrival (first night)'))->required()->live(),
                                    TextInput::make('nights')->label(__('Nights'))->integer()->minValue(1)->maxValue(365)->default(1)->required()->live(debounce: 500),
                                    TextInput::make('anglers')->label(__('Anglers'))->integer()->minValue(1)->maxValue(50)->default(1)->required()->live(debounce: 500),
                                    TextInput::make('companions')->label(__('Companions'))->integer()->minValue(0)->maxValue(50)->default(0)->live(debounce: 500),
                                ]),
                                Repeater::make('services')
                                    ->label(__('Additional services'))
                                    ->schema([
                                        Select::make('service_id')
                                            ->label(__('Service'))
                                            ->options(fn (Get $get): array => AdditionalService::query()
                                                ->where('fishery_id', $get('../../fishery_id'))
                                                ->isActive()
                                                ->orderBy('name')
                                                ->pluck('name', 'id')
                                                ->all())
                                            ->required(),
                                        TextInput::make('quantity')->label(__('Quantity'))->integer()->minValue(1)->maxValue(99)->default(1)->required(),
                                    ])
                                    ->columns(2)
                                    ->defaultItems(0)
                                    ->live(),
                            ])
                            ->columnSpan(1),
                        Section::make(__('Offer'))
                            ->schema([
                                Text::make(fn (Get $get): HtmlString => $this->offerSummary($get)),
                                Toggle::make('agreed')->label(__('Agreed price'))->live(),
                                TextInput::make('agreed_total')->label(__('Agreed total'))->numeric()->minValue(0)
                                    ->visible(fn (Get $get): bool => (bool) $get('agreed'))
                                    ->required(fn (Get $get): bool => (bool) $get('agreed')),
                                TextInput::make('agreed_reason')->label(__('Reason for the agreed price'))->maxLength(500)
                                    ->visible(fn (Get $get): bool => (bool) $get('agreed'))
                                    ->required(fn (Get $get): bool => (bool) $get('agreed')),
                                TextInput::make('exception_reason')
                                    ->label(__('Reason to book against the sale rules'))
                                    ->helperText(__('Needed only when the offer is refused — the exception is recorded with your name.'))
                                    ->maxLength(500),
                                Textarea::make('internal_note')->label(__('Internal note'))->rows(2),
                            ])
                            ->columnSpan(1),
                    ]),
            ]);
    }

    /**
     * ⚠️ Zapis przez serwis — odmowa wraca komunikatem i zatrzymuje zapis (formularz zostaje).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data = FisheryAccess::forceVerifiedFishery($data);
        $fishery = Fishery::query()->findOrFail($data['fishery_id']);

        // ⚠️ Stanowisko i relacja wędkarza przychodzą od klienta — wyłącznie z TEGO łowiska.
        $position = Position::query()->where('fishery_id', $fishery->getKey())->whereKey($data['position_id'] ?? null)->first();
        abort_if($position === null, 404);

        $angler = filled($data['fishery_angler_id'] ?? null)
            ? (self::findAngler($fishery->getKey(), $data['fishery_angler_id']) ?? abort(404))
            : new AnglerDetails(
                name: (string) $data['angler_name'],
                email: $data['angler_email'] ?? null,
                phone: $data['angler_phone'] ?? null,
                notes: $data['angler_notes'] ?? null,
            );

        $outcome = (new ReservationBooking)->book(new BookingRequest(
            position: $position,
            startsOn: (string) $data['starts_on'],
            nights: (int) $data['nights'],
            angler: $angler,
            source: FisheryAccess::isAdminPanel() ? ReservationSource::Admin : ReservationSource::Operator,
            actor: auth()->user(),
            anglers: (int) $data['anglers'],
            companions: (int) ($data['companions'] ?? 0),
            services: self::services($data['services'] ?? []),
            agreedTotalCents: ! empty($data['agreed']) ? ReservationActions::toCents($data['agreed_total'] ?? null) : null,
            agreedPriceReason: ! empty($data['agreed']) ? ($data['agreed_reason'] ?? null) : null,
            exceptionReason: $data['exception_reason'] ?? null,
            internalNote: $data['internal_note'] ?? null,
            confirmBy: ReservationConfirmation::deadlineFor($fishery),
        ));

        if (! $outcome->succeeded || $outcome->reservation === null) {
            Notification::make()->danger()->title(__('The reservation was not saved'))->body($outcome->message())->send();
            $this->halt();
        }

        ReservationConfirmation::afterBooking($outcome->reservation);

        return $outcome->reservation;
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('The reservation was saved');
    }

    public function getRedirectUrl(): string
    {
        // Źródłem prawdy jest ZAPISANY rekord, nie parametr URL.
        $fisheryId = $this->record->fishery_id ?? request()->get('fishery');

        return FisheryNavigation::fisherySectionUrl(ReservationResource::class, ManageReservations::class, $fisheryId);
    }

    public function getTitle(): string
    {
        return __('New reservation');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return FisheryNavigation::fisheryBreadcrumbs(
            $fisheryId,
            __('Reservations'),
            FisheryNavigation::fisherySectionUrl(ReservationResource::class, ManageReservations::class, $fisheryId),
            __('New reservation'),
        );
    }

    protected function getFormActions(): array
    {
        return [$this->getCreateFormAction()->label(__('Save the reservation')), $this->getCancelFormAction()];
    }

    /**
     * Podgląd oferty z warstwy oferty — cena z rozbiciem albo powód odmowy z ostrzeżeniem.
     */
    private function offerSummary(Get $get): HtmlString
    {
        $position = Position::query()->with('fishery')->where('fishery_id', $get('fishery_id'))->find($get('position_id'));
        $startsOn = self::validDate($get('starts_on'));
        $nights = (int) $get('nights');
        $anglers = (int) $get('anglers');

        if ($position === null || $startsOn === null || $nights < 1 || $nights > 365 || $anglers < 1) {
            return new HtmlString(e(__('Pick a position, an arrival date and the number of nights to see the price.')));
        }

        try {
            $offer = new StayOffer($position);
            $verdict = $offer->offer($startsOn, $nights, $anglers, max(0, (int) $get('companions')));
            $services = $offer->services($startsOn, $nights, self::services((array) $get('services')));
        } catch (Throwable) {
            return new HtmlString(e(__('The price can not be computed for these values.')));
        }

        $currency = $position->fishery?->currency?->name;

        if (! $verdict->available) {
            return new HtmlString(
                '<strong>'.e(__('Outside the sale rules')).':</strong> '.e((string) $verdict->reason?->label())
                .'<br>'.e(__('You can still book it — give a reason below; it is recorded as an exception.'))
            );
        }

        $stay = (int) $verdict->breakdown?->totalInCents();
        $lines = ['<strong>'.e(__('Price')).': '.e(AmountFormatter::cents($stay + $services->totalInCents(), $currency)).'</strong>'];
        $lines[] = e(__('Stay')).': '.e(AmountFormatter::cents($stay, $currency));

        foreach ($services->chosen() as $line) {
            $lines[] = e($line->service->name).' × '.$line->quantity.': '.e(AmountFormatter::cents($line->amountInCents(), $currency))
                .($line->exceedsStock() ? ' — '.e(__('not enough units left')) : '');
        }

        return new HtmlString(implode('<br>', $lines));
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return array<int, int>
     */
    private static function services(array $rows): array
    {
        $services = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['service_id'] ?? null)) {
                $services[(int) $row['service_id']] = ($services[(int) $row['service_id']] ?? 0) + max(1, (int) ($row['quantity'] ?? 1));
            }
        }

        return $services;
    }

    /**
     * Podpowiedzi wyłącznie z relacji TEGO łowiska (ADR-024) — bez globalnej wyszukiwarki.
     *
     * @return array<int, string>
     */
    private static function anglerOptions(mixed $fisheryId, ?string $search, ?int $id = null): array
    {
        if (! is_numeric($fisheryId) || FisheryAccess::findFishery((int) $fisheryId) === null) {
            return [];
        }

        return FisheryAngler::query()
            ->with('user')
            ->where('fishery_id', (int) $fisheryId)
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

    private static function findAngler(mixed $fisheryId, mixed $id): ?FisheryAngler
    {
        if (! is_numeric($fisheryId) || ! is_numeric($id)) {
            return null;
        }

        return FisheryAngler::query()->where('fishery_id', (int) $fisheryId)->whereKey((int) $id)->first();
    }

    private static function validDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
