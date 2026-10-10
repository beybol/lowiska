{{--
    Ekran decyzji (E) — rezerwacje dotknięte zmianą konfiguracji (zadanie 044, ADR-027).
    Własny widok: tabela z wyborem decyzji na wiersz. Widok niczego nie liczy — listę daje
    ConfigurationCollisionService, zapis robi akcja strony.
--}}
@php
    $change = $this->change();
    $affected = $this->affected();
    $decisions = $change->kind->decisions();
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $change->kind->label() }}</x-slot>
        <x-slot name="description">
            {{ __('The change is not saved yet. It will be saved together with your decisions. Ongoing stays are never affected.') }}
        </x-slot>

        @if ($change->kind->requiresDecision())
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <span class="text-sm">{{ __('Apply to all') }}:</span>
                @foreach ($decisions as $decision)
                    <x-filament::button size="sm" color="gray" wire:click="applyToAll('{{ $decision->value }}')">{{ $decision->label() }}</x-filament::button>
                @endforeach
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm" data-collision-table>
                <thead>
                    <tr class="text-left">
                        <th class="px-2 py-1.5">{{ __('Angler') }}</th>
                        <th class="px-2 py-1.5">{{ __('Stay') }}</th>
                        <th class="px-2 py-1.5">{{ __('Position') }}</th>
                        <th class="px-2 py-1.5">{{ __('Payment') }}</th>
                        @if ($change->kind->requiresDecision())
                            <th class="px-2 py-1.5">{{ __('Decision') }}</th>
                            <th class="px-2 py-1.5">{{ __('Message to the angler') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($affected as $reservation)
                        <tr class="border-t border-gray-500/20 align-top" wire:key="collision-{{ $reservation->id }}">
                            <td class="px-2 py-2">
                                {{ $reservation->angler?->effectiveContact()['name'] }}
                                <div class="text-xs opacity-70">{{ $reservation->angler?->effectiveContact()['email'] ?? __('no email — goes to "To contact"') }}</div>
                            </td>
                            <td class="px-2 py-2">{{ $reservation->stayLabel() }}</td>
                            <td class="px-2 py-2">{{ $reservation->position?->name }}</td>
                            <td class="px-2 py-2">{{ $reservation->payment_status->label() }}</td>
                            @if ($change->kind->requiresDecision())
                                <td class="px-2 py-2">
                                    @foreach ($decisions as $decision)
                                        <label class="flex items-center gap-1.5">
                                            <input type="radio" wire:model.live="decisions.{{ $reservation->id }}" value="{{ $decision->value }}">
                                            {{ $decision->label() }}
                                        </label>
                                    @endforeach
                                </td>
                                <td class="px-2 py-2">
                                    <textarea wire:model="messages.{{ $reservation->id }}" rows="2" maxlength="2000" class="w-full rounded-lg border border-gray-500/40 bg-transparent px-2 py-1" placeholder="{{ __('Optional — replaces the common message') }}"></textarea>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($change->kind->requiresDecision())
            <label class="mt-4 flex flex-col gap-1 text-sm">
                <span class="font-medium">{{ __('Common message to anglers whose reservation is cancelled') }}</span>
                <textarea wire:model="message" rows="3" maxlength="2000" class="rounded-lg border border-gray-500/40 bg-transparent px-2 py-1.5"></textarea>
                <span class="text-xs opacity-70">{{ __('Optional. It goes into the cancellation email. Reservations without an email address go to the "To contact" list.') }}</span>
            </label>

            <div class="mt-4 flex gap-2">
                <x-filament::button wire:click="apply">{{ __('Save the change and decisions') }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
