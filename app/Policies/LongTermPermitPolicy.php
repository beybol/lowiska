<?php

namespace App\Policies;

use App\Models\LongTermPermit;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LongTermPermitPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:long_term_permit') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, LongTermPermit $longTermPermit): bool
    {
        return $user->can('view:long_term_permit') ||
               ($user->hasRole('owner') && $longTermPermit->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:long_term_permit') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, LongTermPermit $longTermPermit): bool
    {
        return $user->can('update:long_term_permit') ||
               ($user->hasRole('owner') && $longTermPermit->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, LongTermPermit $longTermPermit): bool
    {
        return $user->can('delete:long_term_permit') ||
               ($user->hasRole('owner') && $longTermPermit->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:long_term_permit');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, LongTermPermit $longTermPermit): bool
    {
        return $user->can('force_delete:long_term_permit');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:long_term_permit');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, LongTermPermit $longTermPermit): bool
    {
        return $user->can('restore:long_term_permit');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:long_term_permit');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, LongTermPermit $longTermPermit): bool
    {
        return $user->can('replicate:long_term_permit');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:long_term_permit');
    }
}
