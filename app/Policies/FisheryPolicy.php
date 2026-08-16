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
        // ⚠️ Samo uprawnienie NIE WYSTARCZA. `Helper::addOwnerRole()` nadaje roli
        // `owner` PEŁNY zestaw `*:fishery`, więc `can()` zwracało `true` także dla
        // CUDZEGO rekordu — jedyną ochroną było zawężenie zapytania w zasobie,
        // czyli jedna warstwa zamiast dwóch. Administrator (`is_admin`) widzi
        // wszystko, także gdy ma dodatkowo rolę `owner`
        // (audyt bezpieczeństwa, zadanie 012).
        if (! $user->can('view:fishery')) {
            return false;
        }

        if ($user->hasRole('owner') && ! $user->is_admin) {
            return $fishery->user_id === $user->id;
        }

        return true;
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
        // ⚠️ Samo uprawnienie NIE WYSTARCZA. `Helper::addOwnerRole()` nadaje roli
        // `owner` PEŁNY zestaw `*:fishery`, więc `can()` zwracało `true` także dla
        // CUDZEGO rekordu — jedyną ochroną było zawężenie zapytania w zasobie,
        // czyli jedna warstwa zamiast dwóch. Administrator (`is_admin`) widzi
        // wszystko, także gdy ma dodatkowo rolę `owner`
        // (audyt bezpieczeństwa, zadanie 012).
        if (! $user->can('update:fishery')) {
            return false;
        }

        if ($user->hasRole('owner') && ! $user->is_admin) {
            return $fishery->user_id === $user->id;
        }

        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Fishery $fishery): bool
    {
        // ⚠️ Samo uprawnienie NIE WYSTARCZA. `Helper::addOwnerRole()` nadaje roli
        // `owner` PEŁNY zestaw `*:fishery`, więc `can()` zwracało `true` także dla
        // CUDZEGO rekordu — jedyną ochroną było zawężenie zapytania w zasobie,
        // czyli jedna warstwa zamiast dwóch. Administrator (`is_admin`) widzi
        // wszystko, także gdy ma dodatkowo rolę `owner`
        // (audyt bezpieczeństwa, zadanie 012).
        if (! $user->can('delete:fishery')) {
            return false;
        }

        if ($user->hasRole('owner') && ! $user->is_admin) {
            return $fishery->user_id === $user->id;
        }

        return true;
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
