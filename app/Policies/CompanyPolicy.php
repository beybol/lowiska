<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Company;
use Illuminate\Auth\Access\HandlesAuthorization;

class CompanyPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('view_any_company');
        }

        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Company $company): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('view_company');
        }

        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('create_company');
        }

        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Company $company): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('update_company');
        }

        return true;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Company $company): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('delete_company');
        }

        return true;
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('delete_any_company');
        }

        return true;
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Company $company): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('force_delete_company');
        }

        return true;
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('force_delete_any_company');
        }

        return true;
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Company $company): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('restore_company');
        }

        return true;
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('restore_any_company');
        }

        return true;
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, Company $company): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('replicate_company');
        }

        return true;
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        $panel = filament()->getCurrentPanel();

        if ($panel && $panel->getId() === 'admin') {
            return $user->can('reorder_company');
        }

        return true;
    }
}
