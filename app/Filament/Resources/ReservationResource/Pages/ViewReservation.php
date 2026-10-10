<?php

namespace App\Filament\Resources\ReservationResource\Pages;

use App\Enums\TermsExceptionKind;
use App\Filament\Resources\FisheryResource\Pages\ManageReservations;
use App\Filament\Resources\ReservationResource;
use App\Filament\Resources\ReservationResource\ReservationActions;
use App\Models\Reservation;
use App\Models\ReservationTerms;
use App\Services\AmountFormatter;
use App\Services\FisheryNavigation;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * Szczegóły rezerwacji (ekran D) — `ViewRecord` z infolistą: wędkarz, zamrożone warunki, płatność,
 * historia z kanału `reservations`. Akcje nagłówka to te same modale co na liście i w kalendarzu.
 *
 * ⚠️ Rekord wiąże `ReservationResource::getEloquentQuery()` z zawężeniem do łowisk użytkownika —
 * cudza rezerwacja daje 404, a `ViewRecord::authorizeAccess()` pyta dodatkowo `view` polityki.
 */
class ViewReservation extends ViewRecord
{
    protected static string $resource = ReservationResource::class;

    public function getTitle(): string
    {
        return __('Reservation').' #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return ReservationActions::all();
    }

    public function getBreadcrumbs(): array
    {
        /** @var Reservation $record */
        $record = $this->getRecord();

        return FisheryNavigation::fisheryBreadcrumbs(
            $record->fishery_id,
            __('Reservations'),
            FisheryNavigation::fisherySectionUrl(ReservationResource::class, ManageReservations::class, $record->fishery_id),
            '#'.$record->getKey(),
        );
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Section::make(__('Angler'))->schema([
                        TextEntry::make('angler_name')->label(__('Name'))
                            ->state(fn (Reservation $record): ?string => $record->angler?->effectiveContact()['name']
                                ?? $record->terms?->contact_snapshot['name'] ?? null),
                        TextEntry::make('angler_phone')->label(__('Phone'))->placeholder(__('Not provided'))
                            ->state(fn (Reservation $record): ?string => $record->angler?->effectiveContact()['phone']),
                        TextEntry::make('angler_email')->label(__('Email'))->placeholder(__('Not provided'))
                            ->state(fn (Reservation $record): ?string => $record->angler?->effectiveContact()['email']),
                        TextEntry::make('angler.status')->label(__('Account'))->badge()
                            ->formatStateUsing(fn ($state): ?string => $state?->label()),
                        TextEntry::make('angler.notes')->label(__('Note about the angler'))->placeholder('—'),
                    ])->columns(2),
                    Section::make(__('Reservation'))->schema([
                        TextEntry::make('status')->label(__('Status'))->badge()
                            ->formatStateUsing(fn ($state, Reservation $record): string => ReservationResource::statusLabel($record))
                            ->color(fn ($state): string => ReservationResource::statusColor($state)),
                        TextEntry::make('payment_status')->label(__('Payment'))->badge()
                            ->formatStateUsing(fn ($state): string => $state->label()),
                        TextEntry::make('position_name')->label(__('Position'))
                            ->state(fn (Reservation $record): ?string => $record->terms?->position_snapshot['name'] ?? $record->position?->name),
                        TextEntry::make('stay')->label(__('Stay'))
                            ->state(fn (Reservation $record): string => $record->stayLabel()),
                        TextEntry::make('creator.name')->label(__('Created by'))->placeholder('—')
                            ->helperText(fn (Reservation $record): string => $record->source->label()),
                        TextEntry::make('internal_note')->label(__('Internal note'))->placeholder('—'),
                        TextEntry::make('cancellation')->label(__('Cancellation'))
                            ->visible(fn (Reservation $record): bool => $record->cancelled_at !== null)
                            ->state(fn (Reservation $record): string => collect([
                                $record->cancel_reason?->label(),
                                $record->local($record->cancelled_at)?->format('d.m.Y H:i'),
                                $record->canceller?->name,
                                $record->cancel_message,
                            ])->filter()->implode(' · ')),
                        TextEntry::make('to_contact')->label(__('To contact'))
                            ->visible(fn (Reservation $record): bool => $record->needsContact())
                            ->state(__('The angler has not been notified — contact them by phone.'))
                            ->color('warning'),
                    ])->columns(2),
                ]),
                Section::make(__('Terms at booking'))
                    ->description(__('Frozen when the reservation was made — later configuration changes do not affect them.'))
                    ->schema([
                        TextEntry::make('terms.total_cents')->label(__('Total'))
                            ->formatStateUsing(fn ($state, Reservation $record): string => AmountFormatter::cents((int) $state, $record->terms?->currency)),
                        TextEntry::make('terms.computed_total_cents')->label(__('Computed by the system'))->placeholder(__('No price'))
                            ->formatStateUsing(fn ($state, Reservation $record): string => AmountFormatter::cents((int) $state, $record->terms?->currency)),
                        TextEntry::make('party')->label(__('People'))
                            ->state(fn (Reservation $record): string => __(':anglers anglers, :companions companions', [
                                'anglers' => $record->terms->anglers,
                                'companions' => $record->terms->companions,
                            ])),
                        TextEntry::make('services_summary')->label(__('Additional services'))->placeholder(__('None'))
                            ->state(fn (Reservation $record): ?string => self::servicesSummary($record->terms)),
                        TextEntry::make('refund_summary')->label(__('Refund policy'))
                            ->state(fn (Reservation $record): string => self::refundSummary($record->terms)),
                        RepeatableEntry::make('exceptions_list')->label(__('Exceptions'))
                            ->state(fn (Reservation $record): array => self::exceptions($record->terms))
                            ->visible(fn (Reservation $record): bool => ($record->terms->exceptions ?? []) !== [])
                            ->schema([
                                TextEntry::make('kind')->hiddenLabel(),
                                TextEntry::make('detail')->hiddenLabel(),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make(__('History'))
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('history')->hiddenLabel()
                            ->state(fn (Reservation $record): array => self::history($record))
                            ->schema([
                                TextEntry::make('when')->hiddenLabel(),
                                TextEntry::make('who')->hiddenLabel(),
                                TextEntry::make('what')->hiddenLabel(),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }

    private static function servicesSummary(?ReservationTerms $terms): ?string
    {
        $lines = collect($terms->services ?? [])->map(fn (array $line): string => sprintf(
            '%s × %d — %s',
            $line['name'],
            $line['quantity'],
            AmountFormatter::cents((int) $line['amount_cents'], $terms?->currency),
        ));

        return $lines->isEmpty() ? null : $lines->implode(', ');
    }

    private static function refundSummary(?ReservationTerms $terms): string
    {
        $tiers = $terms->refund_policy ?? [];

        if ($tiers === []) {
            return __('Refund policy not set');
        }

        return collect($tiers)
            ->map(fn (array $tier): string => __(':percent% when cancelled at least :days days before', $tier))
            ->implode('; ');
    }

    /**
     * @return array<int, array{kind: string, detail: string}>
     */
    private static function exceptions(?ReservationTerms $terms): array
    {
        return collect($terms->exceptions ?? [])
            ->map(fn (array $exception): array => [
                'kind' => TermsExceptionKind::tryFrom((string) ($exception['kind'] ?? ''))?->label() ?? (string) ($exception['kind'] ?? ''),
                'detail' => collect([$exception['reason'] ?? null, $exception['by_name'] ?? null, $exception['at'] ?? null])->filter()->implode(' · '),
            ])
            ->all();
    }

    /**
     * Historia z kanału `reservations` — rezerwacja i jej warunki, od najnowszych.
     *
     * @return array<int, array{when: string, who: string, what: string}>
     */
    private static function history(Reservation $record): array
    {
        $termsId = $record->terms?->getKey();

        $activities = Activity::query()
            ->with('causer')
            ->where('log_name', Reservation::LOG_NAME)
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', Reservation::class)->where('subject_id', $record->getKey()))
                ->when($termsId !== null, fn ($q) => $q->orWhere(fn ($inner) => $inner
                    ->where('subject_type', ReservationTerms::class)->where('subject_id', $termsId))))
            ->latest('id')
            ->limit(50)
            ->get();

        $rows = [];

        foreach ($activities as $activity) {
            $createdAt = $activity->created_at === null ? null : CarbonImmutable::instance($activity->created_at);

            $rows[] = [
                'when' => $record->local($createdAt)?->format('d.m.Y H:i') ?? '',
                'who' => (string) ($activity->causer?->getAttribute('name') ?? __('System')),
                'what' => self::describe($activity),
            ];
        }

        return $rows;
    }

    private static function describe(Activity $activity): string
    {
        $subject = $activity->subject_type === ReservationTerms::class ? __('Terms') : __('Reservation');
        $event = match ($activity->event) {
            'created' => __('created'),
            'terms_changed' => __('terms changed'),
            default => __('updated'),
        };
        $changed = array_keys((array) ($activity->attribute_changes['attributes'] ?? []));
        $reason = $activity->properties['reason'] ?? null;

        return trim($subject.' — '.$event
            .($changed !== [] && $activity->event === 'updated' ? ': '.implode(', ', $changed) : '')
            .($reason ? ' ('.$reason.')' : ''));
    }
}
