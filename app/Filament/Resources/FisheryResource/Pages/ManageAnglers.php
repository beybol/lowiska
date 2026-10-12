<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\AnglerLinkStatus;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\ReservationResource;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\Reservation;
use App\Services\FisheryNavigation;
use App\Support\RowActions;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Wędkarze jednego łowiska — podgląd tylko do odczytu (zadanie 048), pozycja SUB-NAWIGACJI zaraz za „Rezerwacjami".
 *
 * ⚠️ Źródłem są WYŁĄCZNIE relacje łowiska (`fishery_anglers`, ADR-024 pkt 2), nigdy `users`. Strona niczego
 * nie zapisuje, a `FisheryAngler` nie ma zasobu ani polityki — dostęp wynika z dostępu do łowiska, tak jak przy
 * „Rezerwacjach" (`autoryzacja.md` §5). Dane efektywne liczy `FisheryAngler::effectiveContact()`; wyszukiwanie
 * i sortowanie w SQL odwzorowuje tę samą regułę (`FisheryAngler::effectiveSql()`).
 */
class ManageAnglers extends ManageRelatedRecords
{
    protected static string $resource = FisheryResource::class;

    protected static string $relationship = 'anglers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getNavigationLabel(): string
    {
        return __('Fishery anglers');
    }

    public function getTitle(): string
    {
        return __('Fishery anglers').': '.$this->fishery()->name;
    }

    private function fishery(): Fishery
    {
        /** @var Fishery $fishery */
        $fishery = $this->getOwnerRecord();

        return $fishery;
    }

    public function getBreadcrumb(): string
    {
        return __('Fishery anglers');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->getKey(), __('Fishery anglers'));
    }

    public function table(Table $table): Table
    {
        $now = fn (): string => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($now): Builder {
                /** @var Builder<FisheryAngler> $query */
                // ⚠️ Strona jedzie po relacji `ownerRecord`, więc eager-load i agregaty dokładamy sami:
                // liczba aktywnych rezerwacji i najbliższy pobyt to agregaty, nie zapytania na wiersz.
                return $query
                    ->with('user')
                    ->withCount(['reservations as active_reservations_count' => function (Builder $reservations): void {
                        /** @var Builder<Reservation> $reservations */
                        $reservations->active();
                    }])
                    ->withMin(['reservations as next_stay_at' => function (Builder $reservations) use ($now): void {
                        /** @var Builder<Reservation> $reservations */
                        $reservations->active()->where('starts_at', '>', $now());
                    }], 'starts_at');
            })
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('next_stay_at is null')
                ->orderBy('next_stay_at')
                ->orderByRaw(FisheryAngler::effectiveSql('name')))
            ->columns([
                TextColumn::make('given_name')
                    ->label(__('Name'))
                    ->state(fn (FisheryAngler $record): ?string => $record->effectiveContact()['name'])
                    ->placeholder(fn (FisheryAngler $record): string => $record->status === AnglerLinkStatus::Revoked ? __('Access revoked') : __('Not provided'))
                    // Stan danych: ikona, a słownie pod nazwiskiem — kolumna „Dane wędkarza" nie zajmuje osobnego miejsca.
                    ->icon(fn (FisheryAngler $record): string => $record->status === AnglerLinkStatus::Linked ? 'heroicon-m-check-badge' : 'heroicon-m-pencil-square')
                    ->iconColor(fn (FisheryAngler $record): string => $record->status->accountColor())
                    ->description(fn (FisheryAngler $record): string => $record->status->accountLabel())
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        /** @var Builder<FisheryAngler> $query */
                        return $query->matchingContact($search);
                    })
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(FisheryAngler::effectiveSql('name').' '.$direction)),
                TextColumn::make('given_email')
                    ->label(__('Contact'))
                    ->state(fn (FisheryAngler $record): ?string => $record->effectiveContact()['email'])
                    ->description(fn (FisheryAngler $record): ?string => $record->effectiveContact()['phone'])
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('active_reservations_count')
                    ->label(__('Active reservations'))
                    ->numeric()
                    // Najbliższy pobyt pod liczbą; po nim domyślnie sortuje cała lista (`defaultSort`).
                    ->description(fn (FisheryAngler $record): ?string => ($next = $this->localMoment($record->getAttribute('next_stay_at'))) !== null
                        ? __('Next stay').': '.$next->format('d.m.Y H:i')
                        : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('data_state')
                    ->label(__('Angler data'))
                    ->options([
                        'provided' => AnglerLinkStatus::Unlinked->accountLabel(),
                        AnglerLinkStatus::Linked->value => AnglerLinkStatus::Linked->accountLabel(),
                        AnglerLinkStatus::Revoked->value => AnglerLinkStatus::Revoked->accountLabel(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'provided' => $query->whereIn('status', [AnglerLinkStatus::Unlinked->value, AnglerLinkStatus::Pending->value]),
                            AnglerLinkStatus::Linked->value => $query->where('status', AnglerLinkStatus::Linked->value),
                            AnglerLinkStatus::Revoked->value => $query->where('status', AnglerLinkStatus::Revoked->value),
                            default => $query,
                        };
                    }),
                Filter::make('has_upcoming')
                    ->label(__('Has an upcoming reservation'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('reservations', function (Builder $reservations) use ($now): void {
                        /** @var Builder<Reservation> $reservations */
                        $reservations->active()->where('starts_at', '>', $now());
                    })),
                Filter::make('to_contact')
                    ->label(__('To contact'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('reservations', function (Builder $reservations): void {
                        /** @var Builder<Reservation> $reservations */
                        $reservations->toContact();
                    })),
            ])
            ->recordActions([RowActions::menu([
                ViewAction::make('preview')
                    ->label(__('View'))
                    ->modalHeading(fn (FisheryAngler $record): string => $record->effectiveContact()['name'] ?? __('Access revoked'))
                    ->slideOver()
                    ->modalWidth('3xl')
                    // ⚠️ Bez domyślnego `attributesToArray()`: pola `given_*` trafiłyby do stanu Livewire w przeglądarce,
                    // także przy cofniętym dostępie. Schemat ma jawne `state()` z `effectiveContact()`.
                    ->fillForm(fn (): array => [])
                    ->schema(fn (): array => $this->previewSchema()),
            ])])
            ->emptyStateHeading(__('No anglers yet'))
            ->emptyStateDescription(__('Anglers appear here after the first reservation made for them.'));
    }

    /**
     * Podgląd wędkarza: dane efektywne, status relacji i rezerwacje z TEGO łowiska (ADR-024 pkt 2).
     *
     * @return array<int, mixed>
     */
    private function previewSchema(): array
    {
        return [
            Section::make(__('Angler'))->schema([
                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    TextEntry::make('given_name')->label(__('Name'))
                        ->state(fn (FisheryAngler $record): ?string => $record->effectiveContact()['name'])
                        ->placeholder(fn (FisheryAngler $record): string => $this->missingLabel($record)),
                    TextEntry::make('given_email')->label(__('Email'))
                        ->state(fn (FisheryAngler $record): ?string => $record->effectiveContact()['email'])
                        ->placeholder(fn (FisheryAngler $record): string => $this->missingLabel($record)),
                    TextEntry::make('given_phone')->label(__('Phone'))
                        ->state(fn (FisheryAngler $record): ?string => $record->effectiveContact()['phone'])
                        ->placeholder(fn (FisheryAngler $record): string => $this->missingLabel($record)),
                    TextEntry::make('status')->label(__('Angler data'))
                        ->state(fn (FisheryAngler $record): AnglerLinkStatus => $record->status)
                        ->badge()
                        ->formatStateUsing(fn (AnglerLinkStatus $state): string => $state->accountLabel())
                        ->color(fn (AnglerLinkStatus $state): string => $state->accountColor()),
                    TextEntry::make('link_status')->label(__('Account link'))
                        ->state(fn (FisheryAngler $record): string => $this->linkDetail($record)),
                ]),
                TextEntry::make('notes')->label(__('Notes'))
                    ->state(fn (FisheryAngler $record): ?string => $record->notes)
                    ->placeholder(__('Not provided'))->columnSpanFull(),
            ]),
            Section::make(__('Reservations'))->schema([
                // Stan z relacji — formularz akcji jest pusty (`fillForm`), każdy wpis ma jawny `state()`.
                RepeatableEntry::make('reservationHistory')
                    ->state(fn (FisheryAngler $record): array => $record->reservationHistory->all())
                    ->hiddenLabel()
                    ->placeholder(__('No reservations'))
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 5])->schema([
                            TextEntry::make('id')->label(__('Reservation'))
                                ->formatStateUsing(fn (int $state): string => '#'.$state)
                                ->url(fn (Reservation $record): ?string => Gate::allows('view', $record)
                                    ? ReservationResource::getUrl('view', ['record' => $record])
                                    : null),
                            TextEntry::make('stay')->label(__('Stay'))
                                ->state(fn (Reservation $record): string => $record->stayLabel()),
                            TextEntry::make('position.name')->label(__('Position')),
                            TextEntry::make('status')->label(__('Status'))
                                ->badge()
                                ->formatStateUsing(fn ($state, Reservation $record): string => ReservationResource::statusLabel($record))
                                ->color(fn ($state, Reservation $record): string => ReservationResource::statusColor($record->status)),
                            TextEntry::make('payment_status')->label(__('Payment'))
                                ->formatStateUsing(fn ($state): string => $state->label()),
                        ]),
                    ]),
            ]),
        ];
    }

    /** Pusta wartość: przy cofniętym dostępie strona mówi to wprost, a nie „nie podano". */
    private function missingLabel(FisheryAngler $record): string
    {
        return $record->status === AnglerLinkStatus::Revoked ? __('Access revoked') : __('Not provided');
    }

    /** Szczegółowy status relacji: prośba z ważnością linku, powiązanie z datą (strefa łowiska). */
    private function linkDetail(FisheryAngler $record): string
    {
        $label = $record->status->label();

        return match ($record->status) {
            AnglerLinkStatus::Pending => $record->link_expires_at !== null
                ? $label.' · '.__('link valid until :date', ['date' => $this->localMoment($record->link_expires_at)?->format('d.m.Y H:i')])
                : $label,
            AnglerLinkStatus::Linked => $record->linked_at !== null
                ? $label.' · '.__('since :date', ['date' => $this->localMoment($record->linked_at)?->format('d.m.Y H:i')])
                : $label,
            default => $label,
        };
    }

    /** Moment z bazy (UTC) w strefie łowiska — prezentacja. */
    private function localMoment(CarbonImmutable|string|null $moment): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        $moment = $moment instanceof CarbonImmutable ? $moment : CarbonImmutable::parse($moment, 'UTC');

        return $moment->setTimezone($this->fishery()->timezoneName());
    }
}
