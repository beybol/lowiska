{{--
    Podgląd szablonu dokumentu — zadanie 021. TYLKO DO ODCZYTU, otwierany w nowej karcie.

    ⚠️ Style wpisane wprost, a nie klasy Tailwinda — panel nie rejestruje własnego motywu
    (ADR-016, `panel-wlasciciela.md` §1). Treść szablonu przechodzi przez `sanitizeHtml()`.
--}}
<x-filament-panels::page>
    @php
        $templates = array_filter($this->templates());
        $selected = $this->selected();
    @endphp

    @if ($templates === [])
        <x-filament::section>
            <p>{{ __('There are no document templates yet.') }}</p>
        </x-filament::section>
    @else
        <x-filament::section>
            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; max-width:480px">
                <span>{{ __('Template') }}</span>
                <select wire:model.live="template" style="padding:6px 8px; border-radius:8px; border:1px solid rgba(120,120,120,.4); background:transparent">
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->type->label() }})</option>
                    @endforeach
                </select>
            </label>
            <p style="margin-top:8px; font-size:12px; opacity:.7">
                {{ __('Read only. Select a fragment and copy it with Ctrl+C.') }}
            </p>
        </x-filament::section>

        @if ($selected !== null)
            <x-filament::section>
                <x-slot name="heading">{{ $selected->name }}</x-slot>
                <div data-template-content style="line-height:1.6">
                    {{ $this->sanitizedContent() }}
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
