{{--
    Pola zgód (zadanie 049, ADR-028) — jedno pole na wymaganą wersję dokumentu, nazwa `consents[<id wersji>]`.
    Brak wersji obowiązującej = brak pola. Etykietę i komunikat błędu daje `ConsentRegistry`.
    Dane: `$documents` (lista `Document`), opcjonalnie `$heading`.
--}}
@if ($documents !== [])
    <fieldset class="flex flex-col gap-2 rounded-md border border-line bg-white px-5 py-4">
        @isset($heading)
            <legend class="px-1 font-semibold text-ink">{{ $heading }}</legend>
        @endisset
        @foreach ($documents as $document)
            @php
                $url = \App\Services\PortalRoutes::documentUrl($document);
            @endphp
            <label class="flex items-start gap-2">
                <input type="checkbox" name="consents[{{ $document->getKey() }}]" value="1" class="mt-1" @checked(old('consents.'.$document->getKey()))>
                <span>
                    {{ \App\Services\ConsentRegistry::label($document) }}
                    @if ($url)
                        · <a href="{{ $url }}" target="_blank" rel="noopener" class="font-semibold text-b600 hover:underline">{{ __('read') }}</a>
                    @endif
                </span>
            </label>
            @error('consents.'.$document->getKey())
                <p class="text-sm text-err">{{ $message }}</p>
            @enderror
        @endforeach
    </fieldset>
@endif
