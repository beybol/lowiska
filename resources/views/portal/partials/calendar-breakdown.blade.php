{{-- Rozbicie ceny pobytu z warstwy oferty (§3.4); usługi obowiązkowe POD sumą, osobno — nie wchodzą do kwoty. --}}
<p class="text-[12.5px] font-semibold text-ink">{{ $tip['title'] }}</p>
<dl class="mt-1.5 space-y-0.5 text-[12.5px]">
    @foreach ($tip['lines'] as $line)
        <div class="flex justify-between gap-3"><dt class="text-ink2">{{ $line['label'] }}</dt><dd class="whitespace-nowrap">{{ $line['amount'] }}</dd></div>
    @endforeach
    @if ($tip['discount'] !== null)
        <div class="flex justify-between gap-3 text-ok"><dt>{{ __('Presale discount') }}</dt><dd class="whitespace-nowrap">{{ $tip['discount'] }}</dd></div>
    @endif
    <div class="flex justify-between gap-3 border-t border-line pt-1 font-semibold"><dt>{{ __('Total') }}</dt><dd class="whitespace-nowrap">{{ $tip['total'] }}</dd></div>
</dl>
@foreach ($tip['services'] as $service)
    <p class="mt-1 text-[11.5px] font-semibold text-a700">{{ $service }}</p>
@endforeach
@if ($tip['services'] !== [])
    <p class="text-[11px] text-muted">{{ __('Next to the price, not in the total — services join the price with online purchase.') }}</p>
@endif
