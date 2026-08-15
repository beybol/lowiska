<?php

namespace App\Policies;

use App\Models\FishingMethod;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FishingMethodPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:fishing_method');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FishingMethod $fishingMethod): bool
    {
        return $user->can('view:fishing_method');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:fishing_method');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FishingMethod $fishingMethod): bool
    {
        return $user->can('update:fishing_method');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FishingMethod $fishingMethod): bool
    {
        return $user->can('delete:fishing_method');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:fishing_method');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, FishingMethod $fishingMethod): bool
    {
        return $user->can('force_delete:fishing_method');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:fishing_method');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, FishingMethod $fishingMethod): bool
    {
        return $user->can('restore:fishing_method');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:fishing_method');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, FishingMethod $fishingMethod): bool
    {
        return $user->can('replicate:fishing_method');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:fishing_method');
    }
}
