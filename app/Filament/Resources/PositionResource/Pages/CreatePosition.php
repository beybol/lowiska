<?php

namespace App\Filament\Resources\PositionResource\Pages;

use App\Filament\Resources\FisheryResource\Pages\ManagePositions;
use App\Filament\Resources\PositionResource;
use App\Helpers\Helper;
use App\Models\AvailabilityBlock;
use App\Models\Position;
use App\Services\PositionAttributeWriter;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePosition extends CreateRecord
{
    protected static string $resource = PositionResource::class;

    protected array $additionalServicesToSync = [];

    /** @var array<int|string, mixed> */
    protected array $attributesToWrite = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->additionalServicesToSync = Helper::extractAdditionalServices($data);

        // ⚠️ Cechy MUSZĄ wypaść ze zbioru przed zapisem modelu: `position_attributes`
        // nie jest kolumną, a zostawione w tablicy trafiłoby do `fill()`.
        $this->attributesToWrite = $data['position_attributes'] ?? [];
        unset($data['position_attributes']);

        return Helper::forceVerifiedFishery($data);
    }

    protected function afterCreate(): void
    {
        Helper::syncAdditionalServices($this->record, $this->additionalServicesToSync);

        $position = $this->record;
        assert($position instanceof Position);

        app(PositionAttributeWriter::class)->writeForPosition($position, $this->attributesToWrite);

        $this->warnAboutBlocksNotCoveringNewPosition($position);
    }

    /**
     * ⚠️ Skutek MATERIALIZOWANIA zbioru blokady: wpis „całe łowisko" trzyma listę
     * konkretnych stanowisk, więc stanowisko założone później NIE wchodzi do niego
     * samo. Operator musi się o tym dowiedzieć przy zapisie, ze wskazaniem, które
     * wpisy go nie obejmują — inaczej nowe stanowisko sprzedaje się w środku
     * zamknięcia całego łowiska (zadanie 016).
     */
    private function warnAboutBlocksNotCoveringNewPosition(Position $position): void
    {
        $blocks = AvailabilityBlock::query()
            ->where('fishery_id', $position->fishery_id)
            ->wholeFishery()
            ->notEndedBefore(now())
            ->get();

        if ($blocks->isEmpty()) {
            return;
        }

        $list = $blocks
            ->map(fn (AvailabilityBlock $block): string => sprintf(
                '%s (%s – %s)',
                $block->reason,
                $block->starts_on->format('d.m.Y'),
                $block->ends_on?->format('d.m.Y') ?? __('until revoked'),
            ))
            ->implode('; ');

        Notification::make()
            ->warning()
            ->title(__('The new position is not covered by existing whole-fishery entries'))
            ->body(__('Add it by hand if it should be: :list', ['list' => $list]))
            ->persistent()
            ->send();
    }

    public function mount(): void
    {
        Helper::assertFisheryAccessOrAbort();
        parent::mount();
    }

    public function getTitle(): string
    {
        return __('Create position');
    }

    public function getBreadcrumbs(): array
    {
        $fisheryId = request()->get('fishery');

        return Helper::fisheryBreadcrumbs(
            $fisheryId,
            __('Positions'),
            self::sectionUrl($fisheryId),
            __('Create'),
        );
    }

    public function getRedirectUrl(): string
    {
        // Źródłem prawdy jest ZAPISANY rekord, nie parametr URL — przy właścicielu
        // dwóch łowisk rekord mógł wylądować w B, a przekierowanie prowadzić do A.
        $fisheryId = $this->record->fishery_id ?? request()->get('fishery');

        return self::sectionUrl($fisheryId);
    }

    private static function sectionUrl(int|string|null $fisheryId): string
    {
        return Helper::fisherySectionUrl(
            PositionResource::class,
            ManagePositions::class,
            $fisheryId,
        );
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            $this->getCreateAnotherFormAction()
                ->label(__('Create and create another position')),
            $this->getCancelFormAction(),
        ];
    }
}
