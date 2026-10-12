<?php

namespace App\Services;

use App\Enums\ConsentChannel;
use App\Enums\ConsentContext;
use App\Enums\ConsentDecision;
use App\Enums\ConsentKind;
use App\Enums\ConsentSender;
use App\Enums\DocumentType;
use App\Models\ConsentEvent;
use App\Models\Document;
use App\Models\Fishery;
use App\Models\FisheryAngler;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * JEDYNE wejście do rejestru zgód i akceptacji (zadanie 049, ADR-028) — zapis i odczyt.
 *
 * - **Wymagane dokumenty** to obowiązujące wersje z flagą `required_at_registration`: Fisherya przy
 *   założeniu konta, łowiska przy połączeniu konta z łowiskiem (od 049 to jest znaczenie tej flagi).
 *   ⚠️ Dopóki dokument nie ma wersji obowiązującej, nie ma czego akceptować — pole zgody się nie pojawia.
 * - Regulamin to **akceptacja**, polityka prywatności — **potwierdzenie zapoznania się**
 *   (`ConsentKind::forDocument()`).
 * - Formularze pytają o zgody polami `consents[<id wersji>]`; reguły i komunikaty daje `rules()`/`messages()`.
 *   Identyfikator wersji w nazwie pola sprawia, że wersja zmieniona między wyświetleniem a wysłaniem
 *   formularza nie zostanie zaakceptowana „w ciemno" — pole nowej wersji jest puste i walidacja odmawia.
 * - **Zgody marketingowe** (nadawca + kanał, per łowisko) mają w 049 wyłącznie odczyt
 *   (`marketingAllowed()`); interfejs i link wypisu — kolejne zadania. Każda wysyłka marketingowa pyta tę metodę.
 */
final class ConsentRegistry
{
    /**
     * @return list<Document>
     */
    public function requiredPlatformDocuments(): array
    {
        $documents = new PlatformDocuments;

        return $this->required(fn (DocumentType $type): ?Document => $documents->current($type));
    }

    /**
     * @return list<Document>
     */
    public function requiredFisheryDocuments(Fishery $fishery): array
    {
        $documents = new FisheryDocuments($fishery);

        return $this->required(fn (DocumentType $type): ?Document => $documents->current($type));
    }

    /**
     * Akceptacja (albo zapoznanie się) każdej z podanych wersji — jedno zdarzenie na wersję.
     *
     * @param  iterable<Document>  $documents
     */
    public function recordDocuments(
        User $user,
        iterable $documents,
        ConsentContext $context,
        ?FisheryAngler $angler = null,
        ?string $ipAddress = null,
        ?string $locale = null,
    ): void {
        foreach ($documents as $document) {
            ConsentEvent::query()->create([
                'user_id' => $user->getKey(),
                'kind' => ConsentKind::forDocument($document->type),
                'decision' => ConsentDecision::Granted,
                'context' => $context,
                'document_id' => $document->getKey(),
                // Wariant, który wędkarz zobaczył — tłumaczenie w jego języku albo treść wiążąca.
                'locale' => $document->variantFor($locale ?? app()->getLocale())['locale'],
                'fishery_id' => $document->fishery_id,
                'fishery_angler_id' => $angler?->getKey(),
                'ip_address' => $ipAddress,
                'occurred_at' => CarbonImmutable::now(),
            ]);
        }
    }

    public function hasAccepted(User $user, Document $document): bool
    {
        return ConsentEvent::query()
            ->where('user_id', $user->getKey())
            ->where('document_id', $document->getKey())
            ->where('decision', ConsentDecision::Granted->value)
            ->exists();
    }

    /**
     * Czy nadawca może wysłać wędkarzowi wiadomość marketingową tym kanałem — ostatnie zdarzenie dla tej
     * pary decyduje; brak zdarzeń = brak zgody. Zgoda łowiska jest per łowisko (ADR-028 §3).
     */
    public function marketingAllowed(User $user, ConsentSender $sender, ConsentChannel $channel, ?Fishery $fishery = null): bool
    {
        $latest = ConsentEvent::query()
            ->where('user_id', $user->getKey())
            ->where('kind', ConsentKind::Marketing->value)
            ->where('sender', $sender->value)
            ->where('channel', $channel->value)
            ->when(
                $sender === ConsentSender::Fishery,
                fn ($query) => $query->where('fishery_id', $fishery?->getKey()),
                fn ($query) => $query->whereNull('fishery_id'),
            )
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $latest instanceof ConsentEvent && $latest->decision === ConsentDecision::Granted;
    }

    /**
     * @param  list<Document>  $documents
     * @return array<string, array<int, string>>
     */
    public static function rules(array $documents): array
    {
        $rules = [];

        foreach ($documents as $document) {
            $rules['consents.'.$document->getKey()] = ['accepted'];
        }

        return $rules;
    }

    /**
     * @param  list<Document>  $documents
     * @return array<string, string>
     */
    public static function messages(array $documents): array
    {
        $messages = [];

        foreach ($documents as $document) {
            $messages['consents.'.$document->getKey().'.accepted'] = __('Confirm: :document.', [
                'document' => $document->variantFor(app()->getLocale())['title'],
            ]);
        }

        return $messages;
    }

    /** Etykieta pola zgody — „Akceptuję…" przy regulaminie, „Zapoznałem się…" przy polityce prywatności. */
    public static function label(Document $document): string
    {
        $title = $document->variantFor(app()->getLocale())['title'];

        return ConsentKind::forDocument($document->type) === ConsentKind::Acknowledgement
            ? __('I have read: :document', ['document' => $title])
            : __('I accept: :document', ['document' => $title]);
    }

    /**
     * @param  callable(DocumentType): ?Document  $current
     * @return list<Document>
     */
    private function required(callable $current): array
    {
        $required = [];

        foreach (DocumentType::cases() as $type) {
            $document = $current($type);

            if ($document instanceof Document && $document->required_at_registration) {
                $required[] = $document;
            }
        }

        return $required;
    }
}
