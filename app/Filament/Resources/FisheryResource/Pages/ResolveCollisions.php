<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\CollisionDecision;
use App\Enums\ConfigurationChangeKind;
use App\Enums\PositionStatus;
use App\Filament\Resources\AvailabilityBlockResource;
use App\Filament\Resources\FisheryResource;
use App\Filament\Resources\PositionResource;
use App\Models\AvailabilityBlock;
use App\Models\Fishery;
use App\Models\Position;
use App\Models\Reservation;
use App\Services\CollisionDrafts;
use App\Services\ConfigurationChange;
use App\Services\ConfigurationCollisionService;
use App\Services\FisheryNavigation;
use App\Services\StaleCollisionDecision;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Ekran decyzji (E) — zmiana konfiguracji koliduje z rezerwacjami (ADR-027, zadanie 044).
 *
 * Jeden ekran dla każdej zmiany: blokady sprzedaży (nowej i edytowanej) i wycofania stanowiska.
 * Szkic zmiany czeka w sesji (`CollisionDrafts`); zmiana zapisuje się dopiero tutaj, razem
 * z decyzjami, w jednej transakcji (`ConfigurationCollisionService::apply()`).
 *
 * ⚠️ Decyzje i komunikaty to dane od klienta: dozwolone wartości sprawdza usługa, a lista rezerwacji
 * liczy się od nowa przy zapisie — rezerwacja dodana w międzyczasie wraca operatorowi do decyzji.
 */
class ResolveCollisions extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FisheryResource::class;

    protected string $view = 'filament.resources.fishery-resource.pages.resolve-collisions';

    public string $token = '';

    /** @var array<int|string, string> id rezerwacji → decyzja */
    public array $decisions = [];

    /** @var array<int|string, string> id rezerwacji → komunikat indywidualny */
    public array $messages = [];

    public string $message = '';

    public static function shouldRegisterNavigation(array $parameters = []): bool
    {
        return false;
    }

    public function mount(int|string $record, string $token): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()?->can('update', $this->getRecord()) ?? false, 404);

        $this->token = $token;
        $change = $this->change();

        foreach ($this->affected() as $reservation) {
            $this->decisions[$reservation->getKey()] = '';
        }

        // Nic już nie koliduje (np. rezerwację anulowano w międzyczasie) — zapis bez ekranu.
        if ($change->kind->requiresDecision() && $this->decisions === []) {
            $this->apply();
        }
    }

    public function getTitle(): string
    {
        return __('Reservations affected by the change');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->getKey(), $this->change()->kind->label());
    }

    public function change(): ConfigurationChange
    {
        $change = CollisionDrafts::get($this->token);

        // Szkic z innego łowiska niż rekord strony albo wygasła sesja — nie ma czego rozstrzygać.
        abort_if($change === null || $change->fisheryId !== (int) $this->fishery()->getKey(), 404);

        return $change;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function affected(): Collection
    {
        return (new ConfigurationCollisionService)->affected($this->change());
    }

    /** „Zastosuj do wszystkich" — ta sama decyzja dla każdej rezerwacji z listy. */
    public function applyToAll(string $decision): void
    {
        if (CollisionDecision::tryFrom($decision) === null) {
            return;
        }

        foreach (array_keys($this->decisions) as $id) {
            $this->decisions[$id] = $decision;
        }
    }

    public function apply(): void
    {
        $change = $this->change();
        $this->authorizeChange($change);

        foreach ($this->decisions as $decision) {
            if (CollisionDecision::tryFrom((string) $decision) === null) {
                Notification::make()->danger()->title(__('Decide for every reservation'))->send();

                return;
            }
        }

        try {
            $result = (new ConfigurationCollisionService)->apply(
                $change,
                $this->decisions,
                fn () => $this->persist($change),
                auth()->user(),
                trim($this->message) !== '' ? $this->message : null,
                $this->messages,
                (string) ($change->payload['fields']['reason'] ?? ''),
            );
        } catch (StaleCollisionDecision) {
            Notification::make()->warning()->title(__('The list of reservations has changed — decide again.'))->send();
            $this->decisions = [];

            foreach ($this->affected() as $reservation) {
                $this->decisions[$reservation->getKey()] = '';
            }

            return;
        }

        CollisionDrafts::forget($this->token);

        Notification::make()->success()
            ->title(__('Saved'))
            ->body(__(':cancelled cancelled, :kept kept. Email sent to :mailed; :notMailed on the "To contact" list.', [
                'cancelled' => $result->cancelled,
                'kept' => $result->kept,
                'mailed' => $result->mailed,
                'notMailed' => $result->notMailed,
            ]))
            ->send();

        $this->redirect($this->returnUrl($change));
    }

    /**
     * Zapis samej zmiany — wołany wewnątrz transakcji usługi kolizji.
     */
    private function persist(ConfigurationChange $change): ?int
    {
        $fishery = $this->fishery();

        if ($change->kind === ConfigurationChangeKind::PositionWithdrawal) {
            $position = Position::query()->where('fishery_id', $fishery->getKey())->whereKey($change->payload['position_id'] ?? null)->firstOrFail();
            $position->update(['status' => PositionStatus::Withdrawn]);

            return null;
        }

        $fields = (array) ($change->payload['fields'] ?? []);
        $fields['fishery_id'] = $fishery->getKey();

        $block = isset($change->payload['block_id'])
            ? AvailabilityBlock::query()->where('fishery_id', $fishery->getKey())->whereKey($change->payload['block_id'])->firstOrFail()
            : new AvailabilityBlock;

        $block->fill($fields)->save();
        $block->positions()->sync($change->positionIds);

        return (int) $block->getKey();
    }

    /** Ta sama polityka co strona, z której przyszła zmiana. */
    private function authorizeChange(ConfigurationChange $change): void
    {
        if ($change->kind === ConfigurationChangeKind::PositionWithdrawal) {
            $position = Position::query()->where('fishery_id', $this->fishery()->getKey())->whereKey($change->payload['position_id'] ?? null)->firstOrFail();
            Gate::authorize('update', $position);

            return;
        }

        isset($change->payload['block_id'])
            ? Gate::authorize('update', AvailabilityBlock::query()->where('fishery_id', $this->fishery()->getKey())->whereKey($change->payload['block_id'])->firstOrFail())
            : Gate::authorize('create', AvailabilityBlock::class);
    }

    private function returnUrl(ConfigurationChange $change): string
    {
        $fisheryId = $this->fishery()->getKey();

        return $change->kind === ConfigurationChangeKind::PositionWithdrawal
            ? FisheryNavigation::fisherySectionUrl(PositionResource::class, ManagePositions::class, $fisheryId)
            : FisheryNavigation::fisherySectionUrl(AvailabilityBlockResource::class, ManageAvailabilityBlocks::class, $fisheryId);
    }

    public function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }
}
