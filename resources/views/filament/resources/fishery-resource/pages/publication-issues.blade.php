{{--
    Lista braków przed publikacją łowiska — zadanie 030.

    Wyłącznie komponenty Filamenta, bez własnych klas: każdy brak to osobny akapit z odsyłaczem
    do ekranu poprawy. Listę liczy `FisheryPublicationReadiness`; widok niczego nie sprawdza.
--}}
@if ($items !== [])
    <div>
        @foreach ($items as $item)
            <p>
                <x-filament::link
                    :href="$item['url']"
                    :color="$item['blocking'] ? 'danger' : 'warning'"
                    :icon="$item['blocking'] ? 'heroicon-m-x-circle' : 'heroicon-m-exclamation-triangle'"
                >
                    {{ $item['label'] }}
                </x-filament::link>
            </p>
        @endforeach
    </div>
@endif
