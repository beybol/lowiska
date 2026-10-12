{{--
    Pola zgód w widokach Breeze (zadanie 049, ADR-028) — jedno pole na wymaganą wersję dokumentu Fisherya,
    nazwa `consents[<id wersji>]`. Brak wersji obowiązującej = brak pola. Dane: `$documents`.
--}}
@foreach ($documents as $document)
    <div class="mt-4">
        <label class="inline-flex items-start gap-2 text-sm text-gray-700">
            <input type="checkbox" name="consents[{{ $document->getKey() }}]" value="1" class="mt-0.5 rounded border-gray-300" @checked(old('consents.'.$document->getKey()))>
            <span>
                {{ \App\Services\ConsentRegistry::label($document) }}
                @if ($url = \App\Services\PortalRoutes::documentUrl($document))
                    · <a href="{{ $url }}" target="_blank" rel="noopener" class="underline">{{ __('read') }}</a>
                @endif
            </span>
        </label>
        <x-input-error :messages="$errors->get('consents.'.$document->getKey())" class="mt-1" />
    </div>
@endforeach
