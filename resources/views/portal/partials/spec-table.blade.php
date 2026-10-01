{{--
    Tabela danych portalu (`table.spec` makiety) — Cennik i Stanowiska (zadanie 038, R4).

    Parametry:
      $columns — nagłówki kolumn (lista tekstów)
      $rows    — wiersze: lista komórek w kolejności kolumn; komórka to tekst albo `HtmlString`
                 (np. z plakietką — HTML składa wołający, z `e()` na danych)
      $primary — indeks kolumny, która na telefonie stoi w pierwszej linii po prawej (kwota); `null` = brak

    ⚠️ JEDEN znacznik na wszystkie szerokości: od `sm` to `<table>` z `<thead>` (czytnik ekranu podaje nazwę
    kolumny przy każdej wartości), poniżej `sm` te same wiersze układają się jak lista z makiety telefonu —
    pierwsza kolumna i kwota w jednej linii, reszta drobnym tekstem pod spodem. `<thead>` zostaje dla czytników
    (`sr-only`). Bez dublowania treści w HTML-u (indeksacja, strona-publiczna.md §5).
--}}
@php
    $primary ??= null;
@endphp
<table class="mt-2.5 w-full border-collapse text-[13.5px] max-sm:block">
    <thead class="max-sm:sr-only">
        <tr>
            @foreach ($columns as $column)
                <th scope="col" class="border-b border-line px-3 pb-2 text-left text-[11px] font-semibold tracking-[.09em] text-faint uppercase">{{ $column }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody class="max-sm:block">
        @foreach ($rows as $row)
            <tr class="hover:bg-surface max-sm:flex max-sm:flex-wrap max-sm:justify-between max-sm:gap-x-3 max-sm:border-b max-sm:border-line2 max-sm:py-2">
                @foreach ($row as $index => $cell)
                    @php
                        $empty = $cell === '' || $cell === null;
                        $role = match (true) {
                            $index === 0 => 'first',
                            $index === $primary => 'primary',
                            default => 'rest',
                        };
                    @endphp
                    <td @class([
                        'border-b border-line2 px-3 py-2.5 align-top max-sm:block max-sm:border-0 max-sm:p-0',
                        'font-semibold' => $role !== 'rest',
                        'text-muted' => $role === 'rest',
                        'max-sm:order-1 max-sm:min-w-0' => $role === 'first',
                        'max-sm:order-2 max-sm:shrink-0 max-sm:text-right' => $role === 'primary',
                        'max-sm:order-3 max-sm:basis-full max-sm:text-[12px]' => $role === 'rest',
                        'max-sm:hidden' => $empty && $role === 'rest',
                    ])>{{ $cell }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
