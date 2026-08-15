<?php

namespace App\Policies;

use App\Models\Fishery;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FisheryPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:fishery');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Fishery $fishery): bool
    {
        return $user->can('view:fishery');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:fishery');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Fishery $fishery): bool
    {
        return $user->can('update:fishery');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Fishery $fishery): bool
    {
        return $user->can('delete:fishery');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:fishery');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Fishery $fishery): bool
    {
        return $user->can('force_delete:fishery');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:fishery');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Fishery $fishery): bool
    {
        return $user->can('restore:fishery');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:fishery');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, Fishery $fishery): bool
    {
        return $user->can('replicate:fishery');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:fishery');
    }
}
