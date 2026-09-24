<?php

namespace App\Services;

/**
 * Co zmieniła synchronizacja uprawnień administratorów (`AdminPermissionSync`, ADR-018).
 */
final readonly class AdminPermissionSyncReport
{
    /**
     * @param  array<int, string>  $granted  adresy kont, którym nadano rolę
     * @param  array<int, string>  $revoked  adresy kont, którym zdjęto rolę
     */
    public function __construct(
        public string $roleName,
        public int $permissionsBefore,
        public int $permissionsAfter,
        public array $granted,
        public array $revoked,
        public int $adminCount,
    ) {}

    public function roleWasComplete(): bool
    {
        return $this->permissionsBefore === $this->permissionsAfter;
    }

    public function changedNothing(): bool
    {
        return $this->roleWasComplete() && $this->granted === [] && $this->revoked === [];
    }
}
