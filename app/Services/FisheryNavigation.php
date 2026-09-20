<?php

namespace App\Services;

use App\Filament\Resources\FisheryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;

/**
 * Adresy, okruszki i akcje powrotu w obrębie jednego łowiska.
 *
 * Adresy sekcji składa się po KLASIE STRONY z `FisheryResource::getPages()`
 * (`docs/conventions/panel-wlasciciela.md` §2).
 */
final class FisheryNavigation
{
    public static function getFisheryTitle(?int $fisheryId, string $baseLabel): string
    {
        if ($fisheryId) {
            // ⚠️ Przez `findFishery()`, nie gołym `Fishery::find()` — tytuł strony
            // ujawniał nazwę cudzego łowiska tak samo jak okruszki (audyt, zadanie 012).
            $fishery = FisheryAccess::findFishery($fisheryId);
            if ($fishery) {
                return __($baseLabel.' for fishery').' '.$fishery->name;
            }
        }

        return __($baseLabel);
    }

    /**
     * Okruszki dla zasobów podrzędnych łowiska:
     * `Łowiska > {nazwa łowiska} > {sekcja} [> {bieżąca strona}]`.
     *
     * „Łowiska" prowadzi do listy łowisk, nazwa łowiska — do jego strony
     * zarządzania, więc z każdego ekranu podrzędnego da się wrócić o poziom wyżej
     * bez cofania w przeglądarce (zadanie 012).
     *
     * Gdy `$fisheryId` jest puste (zasób otwarty bez kontekstu łowiska, np.
     * z panelu administratora), człon z nazwą łowiska po prostu znika.
     *
     * @param  string|null  $sectionUrl  gdy podany, sekcja staje się klikalna,
     *                                   a `$currentLabel` dokłada się jako ostatni,
     *                                   nieklikalny człon
     * @return array<int|string, string>
     */
    public static function fisheryBreadcrumbs(
        int|string|null $fisheryId,
        string $sectionLabel,
        ?string $sectionUrl = null,
        ?string $currentLabel = null,
    ): array {
        $breadcrumbs = [
            FisheryResource::getUrl('index') => __('Fisheries'),
        ];

        $fishery = FisheryAccess::findFishery($fisheryId);

        if ($fishery) {
            $breadcrumbs[FisheryResource::getUrl('manage', ['record' => $fishery])] = $fishery->name;
        }

        if (filled($currentLabel) && filled($sectionUrl)) {
            $breadcrumbs[$sectionUrl] = $sectionLabel;
            $breadcrumbs[] = $currentLabel;

            return $breadcrumbs;
        }

        $breadcrumbs[] = $sectionLabel;

        return $breadcrumbs;
    }

    /**
     * Adres konkretnej sekcji łowiska — strony z sub-nawigacji rekordu.
     *
     * @param  class-string  $pageClass  strona zasobu `FisheryResource`
     */
    public static function fisheryHubUrl(int|string|null $fisheryId, string $pageClass): ?string
    {
        $fishery = FisheryAccess::findFishery($fisheryId);

        if (! $fishery) {
            return null;
        }

        // ⚠️ Adres składa się po KLASIE STRONY, nie po numerze zakładki. Do zadania 016
        // sekcję wskazywał parametr `?relation=N` liczony z pozycji w `getRelations()`,
        // więc przestawienie zakładek cicho przekierowywało zapis na cudzą listę.
        // Sub-nawigacja zniosła tę pułapkę razem z parametrem.
        return route($pageClass::getRouteName(), ['record' => $fishery]);
    }

    /**
     * Adres sekcji łowiska: strona z sub-nawigacji rekordu, a gdy łowiska nie da się
     * ustalić — samodzielna strona listy zasobu jako fallback.
     *
     * Jedna implementacja dla wszystkich sześciu stron Create/Edit zasobów podrzędnych.
     * ⚠️ Wcześniej ta sama metoda była skopiowana sześć razy jako prywatna `sectionUrl()`,
     * więc zmiana reguły fallbacku wymagała edycji sześciu plików.
     *
     * @param  class-string  $resourceClass
     * @param  class-string  $pageClass  strona sekcji w `FisheryResource`
     */
    public static function fisherySectionUrl(
        string $resourceClass,
        string $pageClass,
        int|string|null $fisheryId,
    ): string {
        return self::fisheryHubUrl($fisheryId, $pageClass)
            ?? $resourceClass::getUrl('index', ['fishery' => $fisheryId]);
    }

    public static function getListHeaderActionsForFishery($resourceClass, $fisheryId)
    {
        $actions = [];

        if ($fisheryId) {
            $actions[] = CreateAction::make()
                ->url(fn () => $resourceClass::getUrl('create', ['fishery' => $fisheryId]));
            $actions[] = self::getBackToFisheryManagementAction($fisheryId, 'action');
        } else {
            $actions[] = CreateAction::make();
        }

        return $actions;
    }

    public static function getEditFormActionsForFishery($record, $saveAction, $cancelAction)
    {
        $cancelActionModifier = self::getBackToFisheryManagementAction(
            $record->fishery_id ?? null,
            'cancel',
        );

        return [
            $saveAction,
            $cancelActionModifier($cancelAction),
        ];
    }

    public static function getBackToFisheryManagementAction($fisheryId = null, $actionType = 'cancel')
    {
        $url = function () use ($fisheryId) {
            $id = $fisheryId;

            if (! $id) {
                $id = request()->get('fishery');
            }

            if (! $id && isset($this->record)) {
                $id = $this->record->fishery_id;
            }

            if ($id) {
                return FisheryResource::getUrl('manage', ['record' => $id]);
            }

            return back();
        };

        if ($actionType === 'cancel') {
            return function ($cancelAction) use ($url) {
                return $cancelAction
                    ->label(__('Back to fishery management'))
                    ->url($url);
            };
        } else {
            return Action::make('back_to_fishery')
                ->label(__('Back to fishery management'))
                ->url($url)
                ->color('gray');
        }
    }
}
