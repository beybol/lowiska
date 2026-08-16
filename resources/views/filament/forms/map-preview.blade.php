{{--
    Podgląd mapy Google dla adresu łowiska.

    ⚠️ Zadanie 012: ten widok NIE czyta wartości z DOM-u i NIE ma własnego stanu.
    Adres liczy się serwerowo w `FisheryResource::form()` (`ViewField::viewData()`),
    a pola adresu są `live()`, więc mapa pojawia się sama, gdy adres jest kompletny.

    Nie dokładaj tu przełącznika „pokaż/ukryj" w Alpine: każda aktualizacja pola
    `live()` przerenderowuje ten fragment, więc lokalny stan Alpine (`x-data`)
    resetuje się do wartości początkowej i mapa znika tuż po pokazaniu.
    Poprzednie wersje przewróciły się kolejno na `DOMContentLoaded` (nie pada po
    nawigacji Livewire) i właśnie na tym resecie stanu.
--}}
@php
    $encodedAddress = urlencode(trim($address));
    $apiKey = config('services.google_maps.api_key');
    $embedUrl = $apiKey && $hasCompleteAddress
        ? 'https://www.google.com/maps/embed/v1/place?key='.$apiKey.'&q='.$encodedAddress
        : null;
    $externalUrl = $hasCompleteAddress
        ? 'https://www.google.com/maps/search/?api=1&query='.$encodedAddress
        : null;
@endphp

<div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
    <div class="p-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            @if ($hasCompleteAddress)
                {{ trim($address) }}
            @else
                {{ __('Fill in the address fields to see the map.') }}
            @endif
        </p>
    </div>

    @if ($embedUrl)
        <div class="border-t border-gray-200 dark:border-white/10">
            <iframe
                src="{{ $embedUrl }}"
                width="100%"
                height="350"
                allowfullscreen
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                class="block border-0"
            ></iframe>

            @if ($externalUrl)
                <div class="flex justify-center border-t border-gray-200 p-3 dark:border-white/10">
                    <x-filament::link
                        :href="$externalUrl"
                        target="_blank"
                        icon="heroicon-m-arrow-top-right-on-square"
                    >
                        {{ __('View map in new tab') }}
                    </x-filament::link>
                </div>
            @endif
        </div>
    @elseif ($hasCompleteAddress)
        <div class="border-t border-gray-200 p-4 text-center dark:border-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Map preview not available') }}
            </p>
        </div>
    @endif
</div>
