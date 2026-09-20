<?php

namespace App\Policies;

use App\Models\PositionAttribute;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Słownik cech stanowisk jest WSPÓLNY dla portalu i należy do administratora —
 * właściciel łowiska go nie edytuje. Dlatego polityka nie ma tu żadnego wariantu
 * `hasRole('owner')`, inaczej niż `PositionGroupPolicy`.
 *
 * ⚠️ `PositionAttributeOption` NIE dostaje własnej polityki: nie ma zasobu Filamenta,
 * więc `shield:generate` nigdy nie utworzyłby dla niej uprawnień, a polityka pytająca
 * o nieistniejące uprawnienie wywraca `ShieldPermissionNamesTest`. Opcje żyją przez
 * cechę i autoryzuje je ta polityka (`docs/conventions/autoryzacja.md` §5).
 */
class PositionAttributePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:position_attribute');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PositionAttribute $positionAttribute): bool
    {
        return $user->can('view:position_attribute');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:position_attribute');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PositionAttribute $positionAttribute): bool
    {
        return $user->can('update:position_attribute');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PositionAttribute $positionAttribute): bool
    {
        return $user->can('delete:position_attribute');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:position_attribute');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, PositionAttribute $positionAttribute): bool
    {
        return $user->can('force_delete:position_attribute');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:position_attribute');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, PositionAttribute $positionAttribute): bool
    {
        return $user->can('restore:position_attribute');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:position_attribute');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, PositionAttribute $positionAttribute): bool
    {
        return $user->can('replicate:position_attribute');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:position_attribute');
    }
}
