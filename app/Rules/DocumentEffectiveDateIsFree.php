<?php

namespace App\Rules;

use App\Enums\DocumentType;
use App\Models\Document;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Dwie wersje jednego rodzaju dokumentu nie mogą wchodzić w życie tego samego dnia (zadanie 021).
 *
 * ⚠️ Reguła, nie indeks unikalny (ADR-017, decyzja autora): pomija wersje usunięte miękko, więc
 * usunięcie zaplanowanej wersji zwalnia jej datę. Bez tej reguły nie da się jednoznacznie wskazać
 * wersji obowiązującej.
 */
final class DocumentEffectiveDateIsFree implements ValidationRule
{
    public function __construct(
        private readonly int $fisheryId,
        private readonly DocumentType|string|null $type,
        private readonly ?int $ignoreDocumentId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $type = $this->type instanceof DocumentType ? $this->type : DocumentType::tryFrom((string) $this->type);

        if (blank($value) || $type === null) {
            return;
        }

        $taken = Document::query()
            ->where('fishery_id', $this->fisheryId)
            ->where('type', $type->value)
            ->whereDate('effective_from', substr((string) $value, 0, 10))
            ->when($this->ignoreDocumentId !== null, fn ($query) => $query->whereKeyNot($this->ignoreDocumentId))
            ->exists();

        if ($taken) {
            $fail(__('Another version of this document already takes effect on that day.'));
        }
    }
}
