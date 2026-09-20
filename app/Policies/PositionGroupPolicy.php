<?php

namespace App\Policies;

use App\Models\PositionGroup;
use App\Models\User;
use App\Services\FisheryAccess;
use App\Services\OwnerRoleProvisioner;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Grupa stanowisk należy do łowiska, więc widoczność zawęża się po właścicielu —
 * wzorzec identyczny jak w `PositionPolicy`.
 *
 * ⚠️ Sama polityka jest ZEROWĄ warstwą, nie pierwszą: `OwnerRoleProvisioner::addOwnerRole()` nadaje
 * roli `owner` pełny zestaw uprawnień, więc `$user->can('update:position_group')` jest
 * prawdą także dla cudzego rekordu. Dlatego metody na rekordzie porównują `user_id`,
 * a zasób dokłada `FisheryAccess::scopeToOwnedFisheries()` (`autoryzacja.md` §4).
 */
class PositionGroupPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:position_group') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PositionGroup $positionGroup): bool
    {
        return $user->can('view:position_group') ||
               ($user->hasRole('owner') && $positionGroup->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:position_group') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PositionGroup $positionGroup): bool
    {
        return $user->can('update:position_group') ||
               ($user->hasRole('owner') && $positionGroup->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PositionGroup $positionGroup): bool
    {
        return $user->can('delete:position_group') ||
               ($user->hasRole('owner') && $positionGroup->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:position_group');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, PositionGroup $positionGroup): bool
    {
        return $user->can('force_delete:position_group');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:position_group');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, PositionGroup $positionGroup): bool
    {
        return $user->can('restore:position_group');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:position_group');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, PositionGroup $positionGroup): bool
    {
        return $user->can('replicate:position_group');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:position_group');
    }
}
