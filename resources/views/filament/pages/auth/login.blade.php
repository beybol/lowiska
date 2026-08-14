<x-filament-panels::page.simple>
    <x-filament-panels::form wire:submit="authenticate">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('redirect-to-2fa', (event) => {
                window.location.href = event.url;
            });
        });
    </script>
</x-filament-panels::page.simple>
