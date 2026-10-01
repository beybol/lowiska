<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Enums\FisherySection;
use App\Enums\PublicationIssue;
use App\Filament\Resources\FisheryResource;
use App\Models\Fishery;
use App\Services\FisheryAccess;
use App\Services\FisheryImages;
use App\Services\FisheryPublicationReadiness;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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

    /**
     * Podgląd w układzie formularza (zadanie 038, R5): te same sekcje, kolejność i siatka
     * (`FisheryResource::layout()` po `FisherySection`), każde pole formularza tylko do odczytu.
     *
     * ⚠️ Zmieniasz pole w `FisheryResource::fisheryDetailComponents()` — dopisz wpis tutaj, w tej samej sekcji.
     * Puste pole → „Nie podano"; treści z edytora przez `Str::sanitizeHtml()`; zdjęcia wyłącznie z wariantów.
     */
    public function infolist(Schema $schema): Schema
    {
        $notSpecified = __('Not specified');
        $html = static fn (?string $state): ?HtmlString => blank(strip_tags((string) $state))
            ? null
            : new HtmlString(Str::sanitizeHtml((string) $state));
        $flag = static fn (string $name, string $label): TextEntry => TextEntry::make($name)
            ->label($label)
            ->state(fn (Fishery $record): string => match ($record->{$name}) {
                true => __('Yes'),
                false => __('No'),
                default => __('Not specified'),
            });

        return $schema->columns(1)->components(FisheryResource::layout([
            FisherySection::Basic->value => [
                TextEntry::make('company.name')->label(__('Company'))->placeholder($notSpecified),
                TextEntry::make('name')->label(__('Fishery name')),
                TextEntry::make('slug')
                    ->label(__('Page address (slug)'))
                    ->helperText(fn (): ?string => FisheryAccess::isOwnerPanel()
                        ? __('Set once, from the fishery name. Only the portal administrator can change it.')
                        : null),
                TextEntry::make('user.name')
                    ->label(__('Fishery entered by'))
                    ->hidden(fn (): bool => FisheryAccess::isOwnerPanel()),
                TextEntry::make('published_at')
                    ->label(__('In the portal'))
                    ->dateTime()
                    ->placeholder(__('Not published')),
                // ⚠️ Liczba LICZONA ze stanowisk w sprzedaży, nie kolumna — dawne
                // `positions_count` było drugą prawdą obok tabeli stanowisk (zadanie 030).
                TextEntry::make('positions_for_sale')
                    ->label(__('Positions for sale'))
                    ->state(fn (Fishery $record): int => $record->positions()->available()->count()),
            ],
            FisherySection::Description->value => [
                TextEntry::make('description')
                    ->hiddenLabel()
                    ->formatStateUsing(fn (?string $state): ?HtmlString => $html($state))
                    ->placeholder($notSpecified)
                    ->columnSpanFull(),
            ],
            FisherySection::Address->value => [
                Group::make([
                    TextEntry::make('state.name')
                        ->label(__('State'))
                        ->formatStateUsing(fn (?string $state): string => __((string) $state))
                        ->placeholder($notSpecified),
                    TextEntry::make('town')->label(__('Town'))->placeholder($notSpecified),
                    TextEntry::make('street_and_number')
                        ->label(__('Street'))
                        ->state(fn (Fishery $record): string => trim($record->street.' '.$record->building_number))
                        ->placeholder($notSpecified),
                    TextEntry::make('zip_code')->label(__('Postal code'))->placeholder($notSpecified),
                ]),
                ViewEntry::make('map_preview')
                    ->hiddenLabel()
                    ->view('filament.forms.map-preview')
                    ->viewData(fn (Fishery $record): array => FisheryResource::mapPreviewData(
                        $record,
                        $record->street,
                        $record->building_number,
                        $record->zip_code,
                        $record->town,
                        $record->state_id,
                    )),
                TextEntry::make('directions')
                    ->label(__('Directions'))
                    ->formatStateUsing(fn (?string $state): ?HtmlString => $html($state))
                    ->placeholder($notSpecified)
                    ->columnSpanFull(),
            ],
            FisherySection::Contact->value => [
                TextEntry::make('phone')->label(__('Phone'))->placeholder($notSpecified),
                TextEntry::make('email')->label(__('E-mail'))->placeholder($notSpecified),
                TextEntry::make('contact_hours')->label(__('Contact hours'))->placeholder($notSpecified),
                TextEntry::make('website_url')->label(__('Website'))->placeholder($notSpecified),
                TextEntry::make('facebook_url')->label(__('Facebook page'))->placeholder($notSpecified),
            ],
            FisherySection::Water->value => [
                TextEntry::make('area')->label(__('Area (in hectares)'))->placeholder($notSpecified),
                TextEntry::make('avg_depth')->label(__('Average depth (in meters)'))->placeholder($notSpecified),
                TextEntry::make('max_depth')->label(__('Maximum depth (in meters)'))->placeholder($notSpecified),
                TextEntry::make('dominantFish.name')
                    ->label(__('Dominant fish'))
                    ->formatStateUsing(fn (?string $state): string => __((string) $state))
                    ->placeholder($notSpecified),
                $this->badges('fisheryTypes.name', __('Fishery types')),
                $this->badges('fishingMethods.name', __('Fishing methods')),
                $this->badges('fish.name', __('Available fish')),
                TextEntry::make('records')
                    ->label(__('Fishery records'))
                    ->formatStateUsing(fn (?string $state): ?HtmlString => $html($state))
                    ->placeholder($notSpecified)
                    ->columnSpanFull(),
            ],
            FisherySection::AnglerRules->value => [
                $flag('fishing_license_required', __('Fishing licence required')),
                TextEntry::make('rods_included')->label(__('Rods included in the price'))->placeholder($notSpecified),
                $flag('no_kill', __('No-kill (fish can not be taken)')),
                $flag('campfires_banned', __('Campfires banned')),
            ],
            FisherySection::Conveniences->value => [
                $this->badges('conveniences.name', __('Conveniences'))->hiddenLabel(),
            ],
            FisherySection::Map->value => [
                ViewEntry::make('map_image')
                    ->hiddenLabel()
                    ->view('filament.resources.fishery-resource.pages.fishery-images')
                    ->viewData(fn (Fishery $record): array => [
                        'images' => $this->thumbnails($record, FisheryImages::MAP, 960),
                        'grid' => false,
                    ]),
            ],
            FisherySection::Gallery->value => [
                ViewEntry::make('gallery')
                    ->hiddenLabel()
                    ->view('filament.resources.fishery-resource.pages.fishery-images')
                    ->viewData(fn (Fishery $record): array => [
                        'images' => $this->thumbnails($record, FisheryImages::GALLERY, 480),
                        'grid' => true,
                    ]),
            ],
            FisherySection::Billing->value => [
                TextEntry::make('currency.name')->label(__('Currency for settlement'))->placeholder($notSpecified),
                // Pełny numer — operator i admin widzą dane, które sami wpisali (R5).
                TextEntry::make('bank_account_number')->label(__('Bank account number (IBAN)'))->placeholder($notSpecified),
            ],
        ]));
    }

    /** Lista wyboru jako plakietki — nazwy słownikowe są kluczami tłumaczeń. */
    private function badges(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->badge()
            ->color('gray')
            ->formatStateUsing(fn (?string $state): string => __((string) $state))
            ->placeholder(__('Not specified'))
            ->columnSpanFull();
    }

    /**
     * Miniatury z wariantów (`FisheryImages`) — oryginał nie trafia do HTML-a (ADR-023).
     *
     * @return list<array{src: string, alt: string}>
     */
    private function thumbnails(Fishery $record, string $collection, int $width): array
    {
        $thumbnails = [];

        foreach ($record->getMedia($collection) as $media) {
            $src = FisheryImages::url($media, $width);

            if ($src !== null) {
                $thumbnails[] = ['src' => $src, 'alt' => $media->name];
            }
        }

        return $thumbnails;
    }
}
