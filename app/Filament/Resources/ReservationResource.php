<?php

namespace App\Filament\Resources;

use App\Enums\AnglerLinkStatus;
use App\Enums\ReservationStatus;
use App\Filament\Resources\ReservationResource\Pages\CreateReservation;
use App\Filament\Resources\ReservationResource\Pages\EditReservation;
use App\Filament\Resources\ReservationResource\Pages\ListReservations;
use App\Filament\Resources\ReservationResource\Pages\ViewReservation;
use App\Filament\Resources\ReservationResource\ReservationActions;
use App\Models\Reservation;
use App\Services\AmountFormatter;
use App\Services\FisheryAccess;
use App\Support\RowActions;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rezerwacje — zasób podrzędny łowiska, współdzielony przez oba panele (zadanie 041).
 *
 * ⚠️ Zasób istnieje, bo polityka odpowiada zasobowi (`autoryzacja.md` §5) — Shield generuje z niego
 * uprawnienia `*:reservation`. W głównej nawigacji go nie ma: operator wchodzi z sub-nawigacji łowiska
 * („Rezerwacje", `ManageReservations`), a samodzielna lista zostaje celem deep-linku.
 *
 * ⚠️ Zapis rezerwacji WYŁĄCZNIE przez `ReservationBooking` — formularz C woła serwis zamiast
 * `Model::create()`, akcje modalne (`ReservationActions`) tak samo. Zasób nie liczy ceny ani dostępności.
 */
class ReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('Reservation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Reservations');
    }

    /**
     * Tabela rezerwacji łowiska — zwarta, bo pasek boczny łowiska zabiera szerokość (zadanie 048, uwagi z testów):
     * stan wędkarza i telefon siedzą pod nazwiskiem, płatność pod statusem, a akcje w jednym menu na LEWO od kolumn,
     * więc są widoczne także wtedy, gdy tabela się przewija. Cały wiersz prowadzi do szczegółów.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->recordUrl(fn (Reservation $record): string => self::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('angler.given_name')
                    ->label(__('Angler'))
                    ->state(fn (Reservation $record): ?string => $record->angler?->effectiveContact()['name'])
                    // Stan wędkarza, niezależny od statusu rezerwacji (049): ikona, a słownie pod nazwiskiem.
                    ->icon(fn (Reservation $record): string => $record->angler?->status === AnglerLinkStatus::Linked ? 'heroicon-m-check-badge' : 'heroicon-m-pencil-square')
                    ->iconColor(fn (Reservation $record): string => $record->angler?->status->accountColor() ?? 'gray')
                    ->description(fn (Reservation $record): ?string => collect([$record->angler?->effectiveContact()['phone'], $record->angler?->status->accountLabel()])->filter()->implode(' · ') ?: null)
                    ->wrap()
                    // ⚠️ Wyszukiwanie wyłącznie w relacjach TEGO łowiska (ADR-024) — zapytanie tabeli jest
                    // już zawężone do łowiska, a wyszukiwarka nie sięga do `users`.
                    ->searchable(['given_name', 'given_phone', 'given_email']),
                TextColumn::make('position.name')
                    ->label(__('Position')),
                TextColumn::make('starts_at')
                    ->label(__('Stay'))
                    ->state(fn (Reservation $record): string => $record->stayLabel())
                    ->wrap()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ReservationStatus $state, Reservation $record): string => self::statusLabel($record))
                    ->color(fn (ReservationStatus $state): string => self::statusColor($state))
                    ->description(fn (Reservation $record): string => __('Payment').': '.$record->payment_status->label()),
                TextColumn::make('terms.total_cents')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (?int $state, Reservation $record): ?string => $state === null
                        ? null
                        : AmountFormatter::cents($state, $record->terms?->currency)),
                TextColumn::make('creator.name')
                    ->label(__('Created by'))
                    ->description(fn (Reservation $record): string => $record->source->label())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect(ReservationStatus::cases())->mapWithKeys(fn (ReservationStatus $s): array => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([RowActions::menu([
                Action::make('view')
                    ->label(__('View'))
                    ->icon('heroicon-m-eye')
                    ->url(fn (Reservation $record): string => self::getUrl('view', ['record' => $record])),
                ...ReservationActions::all(),
            ])])
            ->toolbarActions([
                BulkActionGroup::make(ReservationActions::bulk()),
            ]);
    }

    /** „Do potwierdzenia do 12.05 14:00" — termin wygaśnięcia w strefie łowiska. */
    public static function statusLabel(Reservation $record): string
    {
        if ($record->status === ReservationStatus::Pending && $record->confirm_by !== null) {
            return __('Awaiting confirmation until :date', ['date' => $record->local($record->confirm_by)?->format('d.m H:i')]);
        }

        if ($record->isInProgress()) {
            return __('In progress');
        }

        return $record->status->label();
    }

    public static function statusColor(ReservationStatus $status): string
    {
        return match ($status) {
            ReservationStatus::Confirmed => 'success',
            ReservationStatus::Pending => 'warning',
            ReservationStatus::Cancelled => 'danger',
            ReservationStatus::Expired => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
            'create' => CreateReservation::route('/create'),
            'view' => ViewReservation::route('/{record}'),
            'edit' => EditReservation::route('/{record}/edit'),
        ];
    }

    /**
     * ⚠️ Zawężenie do łowisk bieżącego użytkownika — jedyna warstwa działająca przy wiązaniu rekordu
     * na stronie szczegółów (`autoryzacja.md` §4, warstwa 1).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        FisheryAccess::scopeToOwnedFisheries($query);

        return $query->with(self::eagerLoads());
    }

    /**
     * Relacje renderowane w tabeli i w akcjach — bez tego N+1 na każdym wierszu.
     *
     * @return array<int, string>
     */
    public static function eagerLoads(): array
    {
        return ['fishery', 'position', 'angler.user', 'terms', 'creator'];
    }
}
