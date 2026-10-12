<?php

namespace App\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * Akcje wiersza każdej tabeli obu paneli — jedno menu „⋮" (zadanie 048, uwagi z testów).
 *
 * Razem z pozycją akcji po lewej stronie kolumn (`Table::configureUsing()` w `AppServiceProvider`) daje jednolity układ:
 * na początku wiersza zawsze ta sama ikona, niezależnie od liczby akcji, a tabela nie rośnie w poziomie. Grupa znika sama,
 * gdy żadna z akcji nie jest widoczna dla użytkownika.
 */
final class RowActions
{
    /**
     * @param  array<int, Action|ActionGroup>  $actions
     */
    public static function menu(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)
            ->label(__('Actions'))
            ->icon('heroicon-m-ellipsis-vertical')
            ->color('gray');
    }
}
