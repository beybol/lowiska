<?php

namespace Tests\Support;

use App\Services\SharedFormComponents;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/**
 * Najmniejszy formularz z polem ceny — do testów KONTRAKTU `SharedFormComponents::getPriceInput()`.
 *
 * ⚠️ Po zadaniu 020 żaden ekran nie woła pola ceny z minimum większym od zera (usługa i stawka
 * dopuszczają 0,00), więc reguła minimum nie ma już wołającego, na którym dałoby się ją sprawdzić.
 * Kontrakt komponentu zostaje — minimum jest jego parametrem — i ten formularz go pilnuje.
 */
final class PriceInputForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $saved = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([SharedFormComponents::getPriceInput('price', null, 0.01)])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->saved = $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
