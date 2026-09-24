<?php

namespace App\Policies;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Szablony dokumentów należą do administratora Fisheryi (zadanie 021).
 *
 * ⚠️ Właściciel łowiska dostaje WYŁĄCZNIE `view_any:document_template` i `view:document_template`
 * (`OwnerRoleProvisioner`) — lista szablonów przy nowej wersji dokumentu i podgląd. Tworzenie,
 * edycja i usuwanie zostają przy administratorze; zasób jest zarejestrowany tylko w `/admin`.
 */
class DocumentTemplatePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any:document_template');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $user->can('view:document_template');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create:document_template');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $user->can('update:document_template');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $user->can('delete:document_template');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any:document_template');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $user->can('force_delete:document_template');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any:document_template');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $user->can('restore:document_template');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any:document_template');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, DocumentTemplate $documentTemplate): bool
    {
        return $user->can('replicate:document_template');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder:document_template');
    }
}
