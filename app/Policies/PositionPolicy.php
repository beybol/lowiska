<?php

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PositionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:position') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Position $position): bool
    {
        return $user->can('view:position') ||
               ($user->hasRole('owner') && $position->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:position') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Position $position): bool
    {
        return $user->can('update:position') ||
               ($user->hasRole('owner') && $position->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Position $position): bool
    {
        return $user->can('delete:position') ||
               ($user->hasRole('owner') && $position->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:position');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Position $position): bool
    {
        return $user->can('force_delete:position');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:position');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Position $position): bool
    {
        return $user->can('restore:position');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:position');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, Position $position): bool
    {
        return $user->can('replicate:position');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:position');
    }
}
