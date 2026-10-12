<?php

namespace App\Policies;

use App\Models\PlatformDocument;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Dokumenty Fisherya (regulamin, polityka prywatności portalu) należą do administratora portalu
 * (zadanie 049, ADR-028). Rola `owner` nie dostaje żadnego z tych uprawnień; zasób jest zarejestrowany
 * tylko w `/admin`. Nienaruszalność wersji od dnia wejścia w życie pilnuje model, nie polityka.
 */
class PlatformDocumentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:platform_document');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PlatformDocument $platformDocument): bool
    {
        return $user->can('view:platform_document');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:platform_document');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PlatformDocument $platformDocument): bool
    {
        return $user->can('update:platform_document');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PlatformDocument $platformDocument): bool
    {
        return $user->can('delete:platform_document');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:platform_document');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, PlatformDocument $platformDocument): bool
    {
        return $user->can('force_delete:platform_document');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:platform_document');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, PlatformDocument $platformDocument): bool
    {
        return $user->can('restore:platform_document');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:platform_document');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, PlatformDocument $platformDocument): bool
    {
        return $user->can('replicate:platform_document');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:platform_document');
    }
}
