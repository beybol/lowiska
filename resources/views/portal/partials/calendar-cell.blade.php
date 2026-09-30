{{--
    Komórka kalendarza (§3.1). Kolory zarezerwowane: turkus — pakiet i „zacznij wcześniej";
    brzoskwinia — niedostępność z powodu od łowiska. Podpowiedź z rozbiciem (§3.4) otwiera się:
    myszą — wyłącznie na najechanie (⚠️ nie na fokus: kliknięcie zostawiało dymek otwarty do kliknięcia
    gdzie indziej); klawiaturą — na `:focus-visible`; dotykiem — na fokus, ale tylko na urządzeniach
    bez najechania (`hover: none`, tablet), bo tam dotknięcie jest jedyną drogą do rozbicia.
--}}
@if ($cell['state'] === 'sellable')
    @if ($tooltip)
        <div class="group relative rounded-sm px-1 py-1 outline-none hover:bg-b50 focus-visible:ring-2 focus-visible:ring-b400" tabindex="0" data-calendar-tip>
            <span class="block font-semibold whitespace-nowrap text-ink">{{ $cell['price'] }}</span>
            <span class="block text-[11px] whitespace-nowrap text-muted">{{ $cell['nights'] }}@if ($cell['surcharge']) · {{ __('with surcharge') }}@endif</span>
            <div class="invisible absolute top-full left-1/2 z-40 mt-1 w-64 -translate-x-1/2 rounded-md border border-line bg-white p-3 text-left opacity-0 shadow-3 transition group-hover:visible group-hover:opacity-100 group-focus-visible:visible group-focus-visible:opacity-100 [@media(hover:none)]:group-focus-within:visible [@media(hover:none)]:group-focus-within:opacity-100" role="tooltip" data-calendar-tip-body>
                @include('portal.partials.calendar-breakdown', ['tip' => $cell['tooltip']])
            </div>
        </div>
    @else
        <span class="block font-semibold whitespace-nowrap text-ink">{{ $cell['price'] }}</span>
        <span class="block text-[11px] whitespace-nowrap text-muted">{{ $cell['nights'] }}@if ($cell['surcharge']) · {{ __('with surcharge') }}@endif</span>
    @endif
@elseif ($cell['state'] === 'bundle')
    <span class="block rounded-sm bg-b50 px-1 py-1.5 text-[11px] font-semibold whitespace-nowrap text-b700" title="{{ $cell['title'] }}">{{ $cell['text'] }}</span>
@else
    <span class="block rounded-sm px-1 py-1.5 text-[11px] whitespace-nowrap {{ $cell['from_fishery'] ? 'bg-[repeating-linear-gradient(-45deg,var(--color-a100),var(--color-a100)_3px,var(--color-a200)_3px,var(--color-a200)_6px)] font-semibold text-a700' : 'text-faint' }}" title="{{ $cell['title'] }}">{{ $cell['text'] }}</span>
@endif
