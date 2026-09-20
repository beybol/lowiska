<?php

namespace App\Policies;

use App\Models\AvailabilityBlock;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Wpis o dostępności należy do łowiska, więc widoczność zawęża się po właścicielu —
 * wzorzec identyczny jak w `PositionPolicy` i `PositionGroupPolicy`.
 *
 * ⚠️ Tabela pośrednia `availability_block_position` polityki NIE dostaje — nie ma
 * zasobu, więc `shield:generate` nie utworzyłby dla niej uprawnień
 * (`docs/conventions/autoryzacja.md` §5).
 */
class AvailabilityBlockPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:availability_block') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AvailabilityBlock $availabilityBlock): bool
    {
        return $user->can('view:availability_block') ||
               ($user->hasRole('owner') && $availabilityBlock->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:availability_block') || $user->hasRole('owner');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AvailabilityBlock $availabilityBlock): bool
    {
        return $user->can('update:availability_block') ||
               ($user->hasRole('owner') && $availabilityBlock->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AvailabilityBlock $availabilityBlock): bool
    {
        return $user->can('delete:availability_block') ||
               ($user->hasRole('owner') && $availabilityBlock->fishery->user_id === $user->id);
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:availability_block');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, AvailabilityBlock $availabilityBlock): bool
    {
        return $user->can('force_delete:availability_block');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:availability_block');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, AvailabilityBlock $availabilityBlock): bool
    {
        return $user->can('restore:availability_block');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:availability_block');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, AvailabilityBlock $availabilityBlock): bool
    {
        return $user->can('replicate:availability_block');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:availability_block');
    }
}
