{{-- Wariant tekstowy bloku „szczegóły" — pary etykieta: wartość, puste pominięte. --}}
@foreach (array_filter($details, fn ($value) => filled($value)) as $label => $value)
{!! $label !!}: {!! $value !!}
@endforeach
