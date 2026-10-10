{{--
    Kalendarz łowiska — jedna siatka, dwie warstwy (zasada G4; zadania 019 i 042).
    „Oferta" — podgląd konfiguracji (`manage-calendar-offer`), „Rezerwacje" — warstwa operacyjna
    (`manage-calendar-reservations`). Stan warstwy w adresie (`?layer=`).
--}}
<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="{{ __('Calendar layer') }}">
        @foreach (\App\Enums\CalendarLayer::cases() as $layerCase)
            <x-filament::button
                size="sm"
                :color="$this->layer() === $layerCase ? 'primary' : 'gray'"
                :outlined="$this->layer() !== $layerCase"
                wire:click="$set('layer', '{{ $layerCase->value }}')"
                role="tab"
                :aria-selected="$this->layer() === $layerCase ? 'true' : 'false'"
            >
                {{ $layerCase->label() }}
            </x-filament::button>
        @endforeach
    </div>

    @if ($this->layer() === \App\Enums\CalendarLayer::Offer)
        @include('filament.resources.fishery-resource.pages.manage-calendar-offer')
    @else
        @include('filament.resources.fishery-resource.pages.manage-calendar-reservations')
    @endif
</x-filament-panels::page>
