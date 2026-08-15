<?php

namespace App\Policies;

use App\Models\FisheryType;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FisheryTypePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:fishery_type');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, FisheryType $fisheryType): bool
    {
        return $user->can('view:fishery_type');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:fishery_type');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, FisheryType $fisheryType): bool
    {
        return $user->can('update:fishery_type');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, FisheryType $fisheryType): bool
    {
        return $user->can('delete:fishery_type');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:fishery_type');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, FisheryType $fisheryType): bool
    {
        return $user->can('force_delete:fishery_type');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:fishery_type');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, FisheryType $fisheryType): bool
    {
        return $user->can('restore:fishery_type');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:fishery_type');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, FisheryType $fisheryType): bool
    {
        return $user->can('replicate:fishery_type');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:fishery_type');
    }
}
