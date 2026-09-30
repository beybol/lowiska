{{--
    „Jak układamy listę" — obowiązek informacyjny o parametrach plasowania (DECYZJE-I-TODO-BIZNESOWE §2.1,
    portal-v3 §5.1). Treść opisuje STAN KODU: `PortalFisheries::listed()` — zmiana kolejności listy
    wymaga zmiany tej strony.
--}}
@extends('portal.layout')

@section('title', __('How we order the list'))
@section('description', __('The list shows every published fishery, alphabetically. There are no paid or promoted offers.'))

@section('content')
    @include('portal.partials.page', [
        'eyebrow' => __('For anglers'),
        'heading' => __('How we order the list'),
        'body' => '<div class="space-y-4">'
            .'<p>'.e(__('The list shows every fishery published in the portal, alphabetically by name.')).'</p>'
            .'<p>'.e(__('Nothing else sets the order: there are no paid, promoted or sponsored positions, and no fishery pays to appear higher.')).'</p>'
            .'<p>'.e(__('A fishery appears on the list once its operator publishes it, and disappears when the operator withdraws it.')).'</p>'
            .'</div>',
    ])
@endsection
