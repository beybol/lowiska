{{--
    Szkielet strony informacyjnej portalu. `$eyebrow`, `$heading`, a treść w `$slot` (HTML) albo
    „Treść w przygotowaniu", gdy strona czeka na ostateczny tekst (TODO-1, TODO-3 — prawnik).
--}}
<section class="border-b border-line bg-white">
    <div class="mx-auto max-w-3xl px-4 pt-10 pb-7 sm:px-6">
        @isset($eyebrow)
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-b600">{{ $eyebrow }}</p>
        @endisset
        <h1 class="mt-2 font-display text-[30px] font-semibold leading-tight tracking-tight sm:text-[34px]">{{ $heading }}</h1>
    </div>
</section>
<section class="mx-auto max-w-3xl px-4 py-8 text-[15px] text-ink2 sm:px-6">
    @if (($body ?? null) !== null)
        {!! $body !!}
    @else
        <p class="rounded-md border border-dashed border-faint bg-white px-5 py-6 text-muted">{{ __('Content in preparation.') }}</p>
    @endif
</section>
