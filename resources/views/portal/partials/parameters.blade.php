{{-- Siatka par „etykieta — wartość" (Akwen, Zanim przyjedziesz). Puste pozycje odfiltrowuje `PortalFisheryPage`. --}}
<dl class="mt-2.5 grid grid-cols-2 gap-3 sm:grid-cols-4">
    @foreach ($items as $label => $value)
        <div class="rounded-sm border border-line bg-white px-3 py-2.5">
            <dt class="text-[10.5px] font-semibold uppercase tracking-[.09em] text-faint">{{ $label }}</dt>
            <dd class="mt-0.5 text-[14px] font-semibold">{{ $value }}</dd>
        </div>
    @endforeach
</dl>
