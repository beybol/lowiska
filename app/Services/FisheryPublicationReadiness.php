<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\PublicationIssue;
use App\Models\Fishery;
use Carbon\CarbonImmutable;

/**
 * Co brakuje łowisku, zanim pokaże się wędkarzom (zadanie 030, portal-v3 §6).
 *
 * ⚠️ **Punkty konfiguracji sprzedaży NIE są sprawdzane tutaj po swojemu.** Doba, okres sprzedaży,
 * stanowiska i dziura w cenniku pochodzą z tych samych miejsc co stany puste kalendarza
 * podglądowego (019): `SaleCalendar` i `PricingConfigurationAudit`. Drugi sposób liczenia
 * tego samego rozjechałby się z kalendarzem przy pierwszej zmianie reguł.
 *
 * ⚠️ **„Stanowiska są, ale żadne nie jest w sprzedaży" to osobny punkt**, nie zmiana
 * `SaleCalendar::missingSetup()` — tam wycofane stanowiska mają zostać siatką z wierszami
 * „wycofane", a nie mylącym „dodaj stanowiska".
 *
 * Lista liczy się raz na instancję, a instancja żyje tyle, co jedno sprawdzenie — nie ma bufora
 * między żądaniami.
 */
final class FisheryPublicationReadiness
{
    private ?CarbonImmutable $pricingGap = null;

    /** @var array<int, PublicationIssue>|null */
    private ?array $issues = null;

    public function __construct(private readonly Fishery $fishery) {}

    /**
     * @return array<int, PublicationIssue> w kolejności listy ze specyfikacji
     */
    public function issues(): array
    {
        return $this->issues ??= $this->detectIssues();
    }

    /**
     * Czy któryś brak uniemożliwia publikację — dziś wyłącznie brak województwa
     * (`PublicationIssue::blocksPublication()`).
     */
    public function blocksPublication(): bool
    {
        foreach ($this->issues() as $issue) {
            if ($issue->blocksPublication()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pierwsza doba bez stawki — do treści ostrzeżenia.
     */
    public function pricingGap(): ?CarbonImmutable
    {
        $this->issues();

        return $this->pricingGap;
    }

    /**
     * @return array<int, PublicationIssue>
     */
    private function detectIssues(): array
    {
        $issues = [];
        $calendar = new SaleCalendar($this->fishery);
        $missingSetup = $calendar->missingSetup();

        if ($this->fishery->state_id === null) {
            $issues[] = PublicationIssue::StateMissing;
        }

        if ($missingSetup === 'fishing_day') {
            $issues[] = PublicationIssue::FishingDayMissing;
        }

        // `seasons()` to okresy trwające i przyszłe — okres wyłącznie zakończony niczego nie sprzeda.
        if ($calendar->seasons() === []) {
            $issues[] = PublicationIssue::SalePeriodMissing;
        }

        if ($missingSetup === 'position') {
            $issues[] = PublicationIssue::NoPositions;
        } elseif (! $this->fishery->positions()->available()->exists()) {
            $issues[] = PublicationIssue::NoPositionsForSale;
        }

        $this->pricingGap = (new PricingConfigurationAudit($this->fishery))->firstPricingGap();

        if ($this->pricingGap !== null) {
            $issues[] = PublicationIssue::PricingGap;
        }

        if (blank($this->fishery->phone)) {
            $issues[] = PublicationIssue::PhoneMissing;
        }

        if (blank(strip_tags((string) $this->fishery->description))) {
            $issues[] = PublicationIssue::DescriptionMissing;
        }

        if (array_filter((array) $this->fishery->gallery_images) === []) {
            $issues[] = PublicationIssue::PhotoMissing;
        }

        if (blank($this->fishery->map_image_path)) {
            $issues[] = PublicationIssue::MapMissing;
        }

        if ((new FisheryDocuments($this->fishery))->current(DocumentType::Terms) === null) {
            $issues[] = PublicationIssue::TermsMissing;
        }

        return $issues;
    }
}
