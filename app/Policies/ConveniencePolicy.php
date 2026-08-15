<?php

namespace App\Policies;

use App\Models\Convenience;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ConveniencePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:convenience');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Convenience $convenience): bool
    {
        return $user->can('view:convenience');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:convenience');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Convenience $convenience): bool
    {
        return $user->can('update:convenience');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Convenience $convenience): bool
    {
        return $user->can('delete:convenience');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:convenience');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Convenience $convenience): bool
    {
        return $user->can('force_delete:convenience');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:convenience');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Convenience $convenience): bool
    {
        return $user->can('restore:convenience');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:convenience');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, Convenience $convenience): bool
    {
        return $user->can('replicate:convenience');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:convenience');
    }
}
