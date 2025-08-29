<div class="">
    <h1 class="mb-5 text-2xl font-bold">{{ __('Create fishery wizard') }}</h1>

    <div class="mb-2"><strong>{{ __('Step') . ' 1 / 3' }}</strong></div>

    <p class="mb-5">
        {{ __('Before creating a fishery, you should choose or create a company.') }}
    </p>

    @if(count($companies))
        <form wire:submit.prevent="selectCompany" style="margin-bottom: 2rem;">
            <x-input-label 
                required
                for="company_id"
                class="block mb-4 font-semibold"
            >
                {{ __('Choose a company:') }}
            </x-input-label>

            <select 
                name="company_id" 
                id="company_id" 
                wire:model="company_id"
                required
                class="mb-5"
            >
                <option value="">{{ __('None selected') }}</option>

                @foreach($companies as $company)
                    <option value="{{ $company->id }}">
                        {{ $company->name }}
                    </option>
                @endforeach
            </select>

            <div class="mb-5">
                <x-filament::button type="submit" color="success">
                    {{ __('Next') }}
                </x-filament::button>
            </div>
        </form>
    
        <p class="mb-4">{{ __('Or create a new company below.') }}</p>
    @endif
</div>