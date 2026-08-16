{{--
    Wejście do kreatora zakładania łowiska (ekrany logowania i rejestracji).

    ⚠️ Zadanie 012: kreator zaczyna się teraz od formularza ŁOWISKA — firmę
    wybiera/zakłada jego pierwszy krok. Wcześniej ten link prowadził do
    `companies.create?wizard=1`, czyli do osobnej strony udającej krok kreatora.
--}}
<a
    class="mt-4 block text-center underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
    href="{{ route('filament.owner.resources.fisheries.create') }}"
>
    {{ __('Register fishery') }}
</a>
