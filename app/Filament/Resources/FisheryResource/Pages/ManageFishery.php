<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\PublicationIssue;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Services\FisheryPublicationReadiness;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Dane łowiska — pierwsza pozycja sub-nawigacji rekordu.
 *
 * ⚠️ Strona celowo **nie** zawiera formularza edycji łowiska: to podgląd (infolist)
 * z akcją nagłówka „Edytuj" prowadzącą do osobnego formularza. Ta granica jest istotą
 * decyzji z ADR-006 i obowiązuje dalej — nie zamieniaj `ViewRecord` na `EditRecord`.
 *
 * ⚠️ Strona nie ma już zakładek. Od zadania 016 listy zasobów podrzędnych i ekrany
 * konfiguracyjne są osobnymi stronami w `FisheryResource::getRecordSubNavigation()`
 * (ADR-006, aktualizacja z zadania 016).
 *
 * Tu żyje też **publikacja łowiska w portalu** (zadanie 030): „Opublikuj" pokazuje ostrzeżenie
 * o brakach z odsyłaczami do ekranów poprawy, ale ich nie wymusza — poza brakiem województwa.
 * Przyciski są w OBU panelach — admin publikuje i wycofuje każde łowisko (np. przy wdrożeniu
 * albo interwencji), właściciel tylko swoje; rozstrzyga `FisheryPolicy::publish()`, nie panel.
 */
class ManageFishery extends ViewRecord
{
    protected static string $resource = FisheryResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    public static function getNavigationLabel(): string
    {
        return __('Fishery data');
    }

    public function getTitle(): string
    {
        return __('Manage fishery').' '.$this->fishery()->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            FisheryResource::getUrl('index') => __('Fisheries'),
            $this->fishery()->name,
        ];
    }

    /**
     * `getRecord()` deklaruje `Model`, więc każde sięgnięcie po pole łowiska było
     * dla analizy statycznej dostępem do nieznanej właściwości. Zawężenie w jednym
     * miejscu zdjęło dwa wpisy z baseline'u PHPStana (zadanie 012).
     */
    private function fishery(): Fishery
    {
        $record = $this->getRecord();
        assert($record instanceof Fishery);

        return $record;
    }

    public function getBreadcrumb(): string
    {
        return __('Manage');
    }

    /**
     * Strona jest podglądem, więc edycja musi mieć własne wejście — inaczej nie da się
     * przejść do formularza łowiska (zadanie 012).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->publishAction(),
            $this->withdrawAction(),
            EditAction::make()
                ->label(__('Edit'))
                ->icon('heroicon-m-pencil-square')
                ->url(fn (): string => FisheryResource::getUrl('edit', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    /**
     * ⚠️ Ostrzeżenie NIE blokuje publikacji — z jednym wyjątkiem: bez województwa strona łowiska
     * nie ma adresu kanonicznego. Blokadę sprawdza także sama akcja, nie tylko ukryty przycisk
     * zatwierdzenia w modalu — żądanie Livewire da się wysłać z pominięciem interfejsu.
     */
    private function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('Publish'))
            ->icon('heroicon-o-globe-alt')
            ->color('success')
            ->visible(fn (): bool => ! $this->fishery()->isPublished()
                && Gate::allows('publish', $this->fishery()))
            ->modalHeading(__('Publish the fishery in the portal'))
            ->modalDescription(fn (): string => match (true) {
                $this->readiness()->blocksPublication() => __('The fishery can not be published yet.'),
                $this->readiness()->issues() === [] => __('Everything anglers need is in place.'),
                default => __('You can publish the fishery now, but anglers will notice what is missing. You can fix it later.'),
            })
            ->modalContent(fn (): View => view('filament.resources.fishery-resource.pages.publication-issues', [
                'items' => $this->publicationItems(),
            ]))
            ->modalSubmitActionLabel(__('Publish'))
            ->modalSubmitAction(fn (Action $action): Action|false => $this->readiness()->blocksPublication() ? false : $action)
            ->action(function (): void {
                $fishery = $this->fishery();
                Gate::authorize('publish', $fishery);

                if ((new FisheryPublicationReadiness($fishery))->blocksPublication()) {
                    Notification::make()
                        ->danger()
                        ->title(__('The fishery can not be published without a state.'))
                        ->send();

                    return;
                }

                $fishery->update(['published_at' => now()]);

                Notification::make()
                    ->success()
                    ->title(__('The fishery is published in the portal.'))
                    ->send();
            });
    }

    private function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label(__('Withdraw from the portal'))
            ->icon('heroicon-o-eye-slash')
            ->color('gray')
            ->visible(fn (): bool => $this->fishery()->isPublished()
                && Gate::allows('publish', $this->fishery()))
            ->requiresConfirmation()
            ->modalHeading(__('Withdraw the fishery from the portal'))
            ->modalDescription(__('Anglers will no longer find the fishery or open its page. You can publish it again at any time.'))
            ->action(function (): void {
                $fishery = $this->fishery();
                Gate::authorize('publish', $fishery);

                $fishery->update(['published_at' => null]);

                Notification::make()
                    ->success()
                    ->title(__('The fishery is withdrawn from the portal.'))
                    ->send();
            });
    }

    /** Bufor na jedno żądanie — modal pyta o braki kilka razy w tym samym renderze. */
    private ?FisheryPublicationReadiness $readiness = null;

    private function readiness(): FisheryPublicationReadiness
    {
        return $this->readiness ??= new FisheryPublicationReadiness($this->fishery());
    }

    /**
     * @return array<int, array{label: string, url: string, blocking: bool}>
     */
    private function publicationItems(): array
    {
        $gap = $this->readiness()->pricingGap();

        return array_map(fn (PublicationIssue $issue): array => [
            'label' => $issue === PublicationIssue::PricingGap && $gap !== null
                ? __('The pricing has a gap — first night without a rate: :date.', ['date' => $gap->translatedFormat('d.m.Y')])
                : $issue->label(),
            'url' => $this->repairUrl($issue),
            'blocking' => $issue->blocksPublication(),
        ], $this->readiness()->issues());
    }

    /**
     * Ekran, na którym operator usuwa dany brak.
     */
    private function repairUrl(PublicationIssue $issue): string
    {
        $page = match ($issue) {
            PublicationIssue::FishingDayMissing, PublicationIssue::SalePeriodMissing => 'sale-settings',
            PublicationIssue::NoPositions, PublicationIssue::NoPositionsForSale => 'positions',
            PublicationIssue::PricingGap => 'pricing',
            PublicationIssue::TermsMissing => 'documents',
            PublicationIssue::StateMissing, PublicationIssue::PhoneMissing, PublicationIssue::DescriptionMissing,
            PublicationIssue::PhotoMissing, PublicationIssue::MapMissing => 'edit',
        };

        return FisheryResource::getUrl($page, ['record' => $this->fishery()]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Fishery data'))->schema([
                TextEntry::make('name')->label(__('Fishery name')),
                TextEntry::make('company.name')->label(__('Company')),
                TextEntry::make('area')->label(__('Area (in hectares)')),
                // ⚠️ Liczba LICZONA ze stanowisk w sprzedaży, nie kolumna — dawne
                // `positions_count` było drugą prawdą obok tabeli stanowisk (zadanie 030).
                TextEntry::make('positions_for_sale')
                    ->label(__('Positions for sale'))
                    ->state(fn (Fishery $record): int => $record->positions()->available()->count()),
            ])->columns(2),
            Section::make(__('Portal'))->schema([
                TextEntry::make('published_at')
                    ->label(__('In the portal'))
                    ->dateTime()
                    ->placeholder(__('Not published')),
                TextEntry::make('slug')
                    ->label(__('Page address (slug)'))
                    ->helperText(__('Set once, from the fishery name. Only the portal administrator can change it.')),
            ])->columns(2),
            Section::make(__('Contact and links'))->schema([
                TextEntry::make('phone')->label(__('Phone'))->placeholder(__('Not specified')),
                TextEntry::make('email')->label(__('E-mail'))->placeholder(__('Not specified')),
                TextEntry::make('contact_hours')->label(__('Contact hours'))->placeholder(__('Not specified')),
                TextEntry::make('website_url')->label(__('Website'))->placeholder(__('Not specified')),
                TextEntry::make('facebook_url')->label(__('Facebook page'))->placeholder(__('Not specified')),
            ])->columns(2),
            Section::make(__('Fishery address'))->schema([
                TextEntry::make('street')->label(__('Street')),
                TextEntry::make('building_number')->label(__('Building number')),
                TextEntry::make('zip_code')->label(__('Postal code')),
                TextEntry::make('town')->label(__('Town')),
                TextEntry::make('state.name')->label(__('State')),
            ])->columns(2),
        ]);
    }
}
