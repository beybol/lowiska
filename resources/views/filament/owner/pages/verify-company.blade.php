<x-filament-panels::page>
    <div class="mb-2"><strong>{{ __('Step') . ' 2 / 3' }}</strong></div>

    <p>{{ __('Verification transfer') }}</p>
    <p>{{ __('Transfer for 1 złoty is required to verify company.') }}</p>

    <div>
        <x-filament::button
            tag="a"
            href="{{ route('filament.owner.resources.fisheries.create', [
                'company' => request()->query('company'),
                'wizard' => 1,
            ]) }}"
            color="success"
        >
            {{ __('Next') }}
        </x-filament::button>
        <x-filament::button
            tag="a"
            href="{{ route('filament.owner.resources.companies.create', [
                'wizard' => 1,
            ]) }}"
            color="danger"
        >
            {{ __('Previous') }}
        </x-filament::button>
    </div>
</x-filament-panels::page>
