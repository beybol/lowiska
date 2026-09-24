<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Rules\RefundTiersAreValid;
use App\Services\FisheryNavigation;
use App\Services\RefundPolicy;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Ekran „Polityka zwrotu" — progi „N dni przed pobytem → procent zwrotu" (zadanie 021, M7).
 *
 * ⚠️ STRONA USTAWIEŃ zasobu `FisheryResource` (`panel-wlasciciela.md` §6): dostępu pilnuje
 * `FisheryPolicy::update()` przez `EditRecord::authorizeAccess()`, a wiązanie rekordu idzie przez
 * `getEloquentQuery()` z `forCurrentUser()`.
 *
 * ⚠️ Progi to kolumna JSON na łowisku, bieżąca i bez wersji — tak jak cennik. Ekran niczego nie
 * liczy: znaczenie progów (daty w strefie łowiska, granica domknięta, 0% bliżej niż najbliższy)
 * ma jeden dom w `RefundPolicy`.
 */
class ManageRefundPolicy extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    public static function getNavigationLabel(): string
    {
        return __('Refund policy');
    }

    public function getTitle(): string
    {
        return __('Refund policy').': '.$this->fishery()->name;
    }

    public function getBreadcrumb(): string
    {
        return __('Refund policy');
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return FisheryNavigation::fisheryBreadcrumbs($this->fishery()->id, __('Refund policy'));
    }

    public function getRedirectUrl(): string
    {
        return FisheryResource::getUrl('refund-policy', ['record' => $this->fishery()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // ⚠️ Brak progów to „polityka nieustawiona" — nie 0% ani 100%. Panel mówi to wprost.
            Callout::make(__('The refund policy is not set'))
                ->description(__('Without tiers nobody knows how much an angler gets back after cancelling. Add at least one tier.'))
                ->info()
                ->visible(fn (Get $get): bool => RefundPolicy::normalize($get('refund_policy')) === []),
            // ⚠️ Ostrzeżenie, NIE blokada (D2 — portal nie narzuca widełek). Warunek i brzmienie
            // są ROBOCZE i czekają na prawnika (TODO-3, pytania 2 i 3).
            Callout::make(__('This policy never refunds anything'))
                ->description(__('A clause that takes away any refund may be ineffective towards a consumer. The wording of this warning awaits legal review.'))
                ->warning()
                ->visible(fn (Get $get): bool => RefundPolicy::refundsNothingIn($get('refund_policy'))),
            Section::make(__('Refund tiers'))
                ->description(__('How much of the whole paid amount — with all additional services — the angler gets back when cancelling at least N days before the first night. Cancelling closer than the nearest tier refunds nothing; after the first night starts there is no cancellation. A cancellation by the fishery is always a full refund.'))
                ->schema([
                    Repeater::make('refund_policy')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('days')
                                ->label(__('At least this many days before the stay'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(365)
                                ->required(),
                            TextInput::make('percent')
                                ->label(__('Refund (%)'))
                                ->integer()
                                ->minValue(0)
                                ->maxValue(100)
                                ->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->live()
                        ->addActionLabel(__('Add a refund tier'))
                        ->rules([new RefundTiersAreValid]),
                ]),
        ]);
    }

    /**
     * Repeater trzyma wiersze pod kluczami UUID — na wejściu dostaje czystą listę progów.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['refund_policy'] = RefundPolicy::normalize($data['refund_policy'] ?? null);

        return $data;
    }

    /**
     * Zapis w postaci jednoznacznej: liczby całkowite, od najdalszego progu; brak progów = `null`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $tiers = RefundPolicy::normalize($data['refund_policy'] ?? null);
        $data['refund_policy'] = $tiers === [] ? null : $tiers;

        return $data;
    }

    private function fishery(): Fishery
    {
        /** @var Fishery $record */
        $record = $this->getRecord();

        return $record;
    }
}
