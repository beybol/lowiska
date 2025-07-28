<x-guest-layout>

        <div class="mb-4 text-sm text-gray-600">
            {{ new Illuminate\Support\HtmlString(__("Na Twój adres email wysłaliśmy kod autoryzacyjny. Jeśli go nie otrzymałaś/eś, kliknij <a class=\"hover:underline\" href=\":url\"><strong>TUTAJ</strong></a> aby wysłać go ponownie.", ['url' => route('verify.resend')])) }}
        </div>

        <!-- Session Status -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form method="POST" action="{{ route('verify.store') }}">
            @csrf

            <div>
                <x-input-label for="two_factor_code" :value="'Kod autoryzacyjny'" />

                <x-text-input id="two_factor_code" class="mt-1 block w-full"
                              type="text"
                              name="two_factor_code"
                              required
                              autofocus />

                <x-input-error :messages="$errors->get('two_factor_code')" class="mt-2" />
            </div>

            <div class="mt-4 flex justify-end">
                <x-primary-button>
                    Autoryzuj
                </x-primary-button>
            </div>
        </form>

</x-guest-layout>
