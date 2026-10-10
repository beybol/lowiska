<?php

namespace App\Filament\Resources\ReservationResource;

use App\Enums\AnglerLinkStatus;
use App\Enums\CancellationReason;
use App\Enums\ConfirmationSource;
use App\Enums\PaymentStatus;
use App\Enums\ReservationSource;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\BookingOutcome;
use App\Services\BookingRequest;
use App\Services\CancellationRequest;
use App\Services\FisheryAccess;
use App\Services\ReservationBooking;
use App\Services\ReservationMailer;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Krótkie akcje na rezerwacji w MODALACH — współdzielone przez listę (B), szczegóły (D) i kalendarz
 * (zadanie 042): edycja, anulacja, potwierdzenie, status płatności, „do kontaktu".
 *
 * ⚠️ **Świadome odstępstwo od „akcje prowadzą na pełne strony"** (`panel-wlasciciela.md` §2). Tamta
 * reguła chroni formularze z polem `fishery_id` od klienta; tutaj rekord przychodzi z zawężonego
 * zapytania zasobu, akcja pyta politykę jawnie przed wykonaniem, a zapis idzie przez
 * `ReservationBooking`, który sam pilnuje, że stanowisko należy do łowiska rezerwacji. Założenie
 * rezerwacji (formularz C) zostaje pełną stroną.
 *
 * ⚠️ Każda akcja sprawdza `update` na rezerwacji DWA razy — w `visible()` (przycisk) i w `action()`
 * (żądanie Livewire da się wysłać z pominięciem przycisku).
 */
final class ReservationActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [self::edit(), self::confirm(), self::payment(), self::markContacted(), self::cancel()];
    }

    public static function edit(): Action
    {
        return Action::make('editReservation')
            ->label(__('Edit'))
            ->icon('heroicon-m-pencil-square')
            ->modalHeading(__('Edit reservation'))
            ->modalWidth('3xl')
            ->visible(fn (Reservation $record): bool => $record->isActive() && Gate::allows('update', $record))
            ->fillForm(fn (Reservation $record): array => self::editState($record))
            ->schema(fn (Reservation $record): array => self::editSchema($record))
            ->action(function (Reservation $record, array $data, Action $action): void {
                Gate::authorize('update', $record);
                $outcome = self::rebook($record, $data);

                if (! $outcome->succeeded) {
                    Notification::make()->danger()->title(__('The reservation was not changed'))->body($outcome->message())->send();
                    $action->halt();
                }

                Notification::make()->success()->title(__('The reservation was changed'))->send();
            });
    }

    public static function confirm(): Action
    {
        return Action::make('confirmReservation')
            ->label(__('Confirm'))
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Confirm the reservation'))
            ->visible(fn (Reservation $record): bool => $record->status->value === 'pending' && Gate::allows('update', $record))
            ->action(function (Reservation $record): void {
                Gate::authorize('update', $record);
                (new ReservationBooking)->confirm($record, ConfirmationSource::Operator, auth()->user());
                Notification::make()->success()->title(__('The reservation was confirmed'))->send();
            });
    }

    public static function payment(): Action
    {
        return Action::make('reservationPayment')
            ->label(__('Payment'))
            ->icon('heroicon-m-banknotes')
            ->modalHeading(__('Payment status'))
            ->visible(fn (Reservation $record): bool => $record->isActive() && Gate::allows('update', $record))
            ->fillForm(fn (Reservation $record): array => ['payment_status' => $record->payment_status->value])
            ->schema([self::paymentSelect()])
            ->action(function (Reservation $record, array $data): void {
                Gate::authorize('update', $record);
                (new ReservationBooking)->setPaymentStatus($record, PaymentStatus::from($data['payment_status']));
                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    /** „Oznacz jako opłaconą" — skrót kalendarza do statusu płatności (zadanie 042). */
    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label(__('Mark as paid'))
            ->icon('heroicon-m-banknotes')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('Mark the reservation as paid'))
            ->visible(fn (Reservation $record): bool => $record->isActive()
                && $record->payment_status !== PaymentStatus::Paid
                && Gate::allows('update', $record))
            ->action(function (Reservation $record): void {
                Gate::authorize('update', $record);
                (new ReservationBooking)->setPaymentStatus($record, PaymentStatus::Paid);
                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    public static function markContacted(): Action
    {
        return Action::make('markContacted')
            ->label(__('Mark as handled'))
            ->icon('heroicon-m-phone')
            ->requiresConfirmation()
            ->modalHeading(__('The angler has been contacted'))
            ->visible(fn (Reservation $record): bool => $record->needsContact() && Gate::allows('update', $record))
            ->action(function (Reservation $record): void {
                Gate::authorize('update', $record);
                (new ReservationBooking)->markContacted($record);
                Notification::make()->success()->title(__('Saved'))->send();
            });
    }

    /**
     * Anulacja z komunikatem do wędkarza i zwrotem z warunków umowy albo jawnym wyjątkiem.
     *
     * ⚠️ Pobyt w toku: modal najpierw pyta wprost (ADR-027, aktualizacja 10.10) — bez zaznaczenia
     * potwierdzenia zapis nie przechodzi walidacji.
     */
    public static function cancel(): Action
    {
        return Action::make('cancelReservation')
            ->label(__('Cancel…'))
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->modalHeading(__('Cancel the reservation'))
            ->modalSubmitActionLabel(fn (Reservation $record): string => $record->isInProgress()
                ? __('Yes, cancel the ongoing reservation')
                : __('Cancel the reservation'))
            ->visible(fn (Reservation $record): bool => $record->isActive() && Gate::allows('update', $record))
            ->fillForm(['refund' => 'terms'])
            ->schema(fn (Reservation $record): array => self::cancelSchema($record))
            ->action(function (Reservation $record, array $data, Action $action): void {
                Gate::authorize('update', $record);
                $outcome = self::cancelOne($record, $data);

                if (! $outcome->succeeded) {
                    Notification::make()->danger()->title(__('The reservation was not cancelled'))->body($outcome->message())->send();
                    $action->halt();
                }
            });
    }

    /**
     * Akcje zbiorcze listy (B): potwierdź, status płatności, anuluj z komunikatem.
     *
     * @return array<int, BulkAction>
     */
    public static function bulk(): array
    {
        return [
            BulkAction::make('bulkConfirm')
                ->label(__('Confirm'))
                ->icon('heroicon-m-check-circle')
                ->requiresConfirmation()
                ->action(function (Collection $records): void {
                    foreach (self::authorized($records) as $record) {
                        (new ReservationBooking)->confirm($record, ConfirmationSource::Operator, auth()->user());
                    }

                    Notification::make()->success()->title(__('Saved'))->send();
                }),
            BulkAction::make('bulkPayment')
                ->label(__('Payment status'))
                ->icon('heroicon-m-banknotes')
                ->schema([self::paymentSelect()])
                ->action(function (Collection $records, array $data): void {
                    foreach (self::authorized($records) as $record) {
                        (new ReservationBooking)->setPaymentStatus($record, PaymentStatus::from($data['payment_status']));
                    }

                    Notification::make()->success()->title(__('Saved'))->send();
                }),
            BulkAction::make('bulkCancel')
                ->label(__('Cancel…'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->modalHeading(__('Cancel the selected reservations'))
                ->modalDescription(__('Ongoing stays are skipped — cancel them one by one.'))
                ->schema([self::messageField()])
                ->action(function (Collection $records, array $data): void {
                    $cancelled = 0;

                    foreach (self::authorized($records) as $record) {
                        // Pobyt w toku wymaga osobnego potwierdzenia — akcja zbiorcza go pomija.
                        if ($record->isInProgress()) {
                            continue;
                        }

                        if (self::cancelOne($record, ['refund' => 'terms', 'message' => $data['message'] ?? null], notify: false)->succeeded) {
                            $cancelled++;
                        }
                    }

                    Notification::make()->success()
                        ->title(trans_choice(':count reservation cancelled|:count reservations cancelled', $cancelled, ['count' => $cancelled]))
                        ->send();
                }),
        ];
    }

    /**
     * Anulacja jednej rezerwacji: zapis przez serwis, potem mail (albo „Do kontaktu").
     *
     * @param  array<string, mixed>  $data
     */
    public static function cancelOne(Reservation $record, array $data, bool $notify = true): BookingOutcome
    {
        $custom = ($data['refund'] ?? 'terms') === 'custom';
        $outcome = (new ReservationBooking)->cancel($record, new CancellationRequest(
            reason: CancellationReason::FisheryDecision,
            actor: auth()->user(),
            message: $data['message'] ?? null,
            refundPercent: $custom ? (int) $data['refund_percent'] : null,
            refundReason: $custom ? (string) $data['refund_reason'] : null,
        ));

        if (! $outcome->succeeded) {
            return $outcome;
        }

        $sent = (new ReservationMailer)->cancellation($record->fresh() ?? $record);

        if ($notify) {
            Notification::make()->success()
                ->title(__('The reservation was cancelled'))
                ->body($sent
                    ? __('The angler was notified by email.')
                    : __('The angler could not be emailed — the reservation is on the "To contact" list.'))
                ->send();
        }

        return $outcome;
    }

    /**
     * @param  Collection<int, Reservation>  $records
     * @return Collection<int, Reservation>
     */
    private static function authorized(Collection $records): Collection
    {
        return $records->filter(fn (Reservation $record): bool => Gate::allows('update', $record));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function rebook(Reservation $record, array $data): BookingOutcome
    {
        $record->loadMissing(['angler', 'terms', 'fishery']);

        // ⚠️ Stanowisko przychodzi od klienta — wyłącznie z łowiska rezerwacji.
        $position = Position::query()
            ->where('fishery_id', $record->fishery_id)
            ->whereKey($data['position_id'] ?? null)
            ->first();

        // Opcje pola są zawężone do łowiska, więc obce id przychodzi wyłącznie z podmienionego żądania.
        abort_if($position === null, 404);

        $angler = $record->angler;

        // Dane operatora poprawiają WYŁĄCZNIE relację niepowiązaną — konta wędkarza operator nie pisze.
        if (in_array($angler->status, [AnglerLinkStatus::Unlinked, AnglerLinkStatus::Pending], true)) {
            $angler->fill([
                'given_name' => $data['angler_name'] ?? $angler->given_name,
                'given_phone' => $data['angler_phone'] ?? null,
                'given_email' => $data['angler_email'] ?? null,
            ]);

            if ($angler->isDirty()) {
                $angler->save();
            }
        }

        $services = collect($record->terms->services ?? [])
            ->mapWithKeys(fn (array $line): array => [(int) $line['service_id'] => (int) $line['quantity']])
            ->all();

        return (new ReservationBooking)->rebook($record, new BookingRequest(
            position: $position,
            startsOn: (string) $data['starts_on'],
            nights: (int) $data['nights'],
            angler: $angler,
            source: FisheryAccess::isAdminPanel() ? ReservationSource::Admin : ReservationSource::Operator,
            actor: auth()->user(),
            anglers: (int) $data['anglers'],
            companions: (int) ($data['companions'] ?? 0),
            services: $services,
            agreedTotalCents: ! empty($data['agreed']) ? self::toCents($data['agreed_total'] ?? null) : null,
            agreedPriceReason: ! empty($data['agreed']) ? ($data['agreed_reason'] ?? null) : null,
            exceptionReason: $data['exception_reason'] ?? null,
            internalNote: $data['internal_note'] ?? $record->internal_note,
        ), (string) $data['change_reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function editState(Reservation $record): array
    {
        $record->loadMissing(['angler', 'terms']);
        $agreed = $record->terms?->agreed_total_cents;

        return [
            'angler_name' => $record->angler?->given_name,
            'angler_phone' => $record->angler?->given_phone,
            'angler_email' => $record->angler?->given_email,
            'position_id' => $record->position_id,
            'starts_on' => $record->first_night_on->toDateString(),
            'nights' => $record->nights,
            'anglers' => $record->terms->anglers,
            'companions' => $record->terms->companions,
            'agreed' => $agreed !== null,
            'agreed_total' => $agreed === null ? null : number_format($agreed / 100, 2, '.', ''),
            'agreed_reason' => null,
            'internal_note' => $record->internal_note,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function editSchema(Reservation $record): array
    {
        $anglerEditable = in_array($record->angler?->status, [AnglerLinkStatus::Unlinked, AnglerLinkStatus::Pending], true);

        return [
            Section::make(__('Angler'))
                ->description($anglerEditable ? null : __('The angler manages their own contact details in their account.'))
                ->schema([
                    TextInput::make('angler_name')->label(__('Name'))->required()->maxLength(255)->disabled(! $anglerEditable),
                    TextInput::make('angler_phone')->label(__('Phone'))->tel()->maxLength(32)->disabled(! $anglerEditable),
                    TextInput::make('angler_email')->label(__('Email'))->email()->maxLength(255)->disabled(! $anglerEditable),
                ])
                ->columns(3),
            Section::make(__('Stay'))
                ->schema([
                    Select::make('position_id')
                        ->label(__('Position'))
                        ->options(fn (): array => Position::query()->where('fishery_id', $record->fishery_id)->orderBy('name')->pluck('name', 'id')->all())
                        ->required(),
                    DatePicker::make('starts_on')->label(__('Arrival (first night)'))->required(),
                    TextInput::make('nights')->label(__('Nights'))->integer()->minValue(1)->maxValue(365)->required(),
                    TextInput::make('anglers')->label(__('Anglers'))->integer()->minValue(1)->maxValue(50)->required(),
                    TextInput::make('companions')->label(__('Companions'))->integer()->minValue(0)->maxValue(50)->default(0),
                ])
                ->columns(5),
            Section::make(__('Price and exceptions'))
                ->schema([
                    Toggle::make('agreed')->label(__('Agreed price'))->live(),
                    Grid::make(2)->schema([
                        TextInput::make('agreed_total')->label(__('Agreed total'))->numeric()->minValue(0)
                            ->required(fn (Get $get): bool => (bool) $get('agreed')),
                        TextInput::make('agreed_reason')->label(__('Reason for the agreed price'))->maxLength(500)
                            ->required(fn (Get $get): bool => (bool) $get('agreed')),
                    ])->visible(fn (Get $get): bool => (bool) $get('agreed')),
                    TextInput::make('exception_reason')
                        ->label(__('Reason to book against the sale rules'))
                        ->helperText(__('Needed only when the new term breaks the sale rules or a block.'))
                        ->maxLength(500),
                    Textarea::make('internal_note')->label(__('Internal note'))->rows(2),
                ]),
            TextInput::make('change_reason')->label(__('Reason for the change'))->required()->maxLength(500),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function cancelSchema(Reservation $record): array
    {
        $fields = [];

        if ($record->isInProgress()) {
            $fields[] = Text::make(__('This stay is already in progress. Are you sure you want to cancel the ongoing reservation?'))
                ->color('danger');
            $fields[] = Checkbox::make('confirm_in_progress')
                ->label(__('Yes, the stay is in progress and I want to cancel it'))
                ->accepted()
                ->required();
        }

        return array_merge($fields, [
            Radio::make('refund')
                ->label(__('Refund'))
                ->options([
                    'terms' => __('As in the reservation terms (cancelled by the fishery: full refund)'),
                    'custom' => __('Other refund — an exception with a reason'),
                ])
                ->live()
                ->required(),
            Grid::make(2)->schema([
                TextInput::make('refund_percent')->label(__('Refund (%)'))->integer()->minValue(0)->maxValue(100)
                    ->required(fn (Get $get): bool => $get('refund') === 'custom'),
                TextInput::make('refund_reason')->label(__('Reason for the refund'))->maxLength(500)
                    ->required(fn (Get $get): bool => $get('refund') === 'custom'),
            ])->visible(fn (Get $get): bool => $get('refund') === 'custom'),
            self::messageField(),
        ]);
    }

    private static function messageField(): Textarea
    {
        return Textarea::make('message')
            ->label(__('Message to the angler'))
            ->helperText(__('Optional. It goes into the cancellation email.'))
            ->rows(3)
            ->maxLength(2000);
    }

    private static function paymentSelect(): Select
    {
        return Select::make('payment_status')
            ->label(__('Payment status'))
            ->options([
                PaymentStatus::Unpaid->value => PaymentStatus::Unpaid->label(),
                PaymentStatus::OnSite->value => PaymentStatus::OnSite->label(),
                PaymentStatus::Paid->value => PaymentStatus::Paid->label(),
            ])
            ->required();
    }

    public static function toCents(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round(((float) str_replace(',', '.', (string) $amount)) * 100);
    }
}
