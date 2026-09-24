<?php

namespace App\Filament\Resources\FisheryResource\Pages;

use App\Filament\Resources\FisheryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFishery extends EditRecord
{
    protected static string $resource = FisheryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function getTitle(): string
    {
        return __('Edit fishery');
    }

    /**
     * ⚠️ Flagi wymagań wobec wędkarza wracają z bazy jako `bool`, a stanem `Select`a z opcjami
     * 1/0 musi być liczba: `(string) false` to pusty łańcuch, czyli „nie podano", więc „nie"
     * znikałoby z formularza i ginęło przy zapisie (`panel-admina.md` §2). `null` zostaje `null`.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (FisheryResource::ANGLER_RULE_FLAGS as $flag) {
            if (is_bool($data[$flag] ?? null)) {
                $data[$flag] = (int) $data[$flag];
            }
        }

        return $data;
    }
}
