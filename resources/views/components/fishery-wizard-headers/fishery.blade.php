<div>
    <h1 class="mb-5 text-2xl font-bold">{{ __('Create fishery wizard') }}</h1>

    <div class="mb-2"><strong>{{ __('Step') . ' 3 / 3' }}</strong></div>

    @if (request()->query('verified_earlier', 0))
        <p class="mb-4 p-4 rounded border">
            {{ __('The selected company was verified earlier, so we skipped step 2.') }}
        </p>
    @endif

    <p>{{ __('Final step - provide fishery details below.') }}</p>
</div>