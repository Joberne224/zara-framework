<?php
declare(strict_types=1);

namespace Zara\Permission;

use InvalidArgumentException;

final class PermissionRegistry
{
    /** @var array<string, list<string>> */
    private array $permissionsByRole;

    /**
     * @param array<string, list<string>> $permissionsByRole Role names mapped to granted permission names
     */
    public function __construct(array $permissionsByRole = [])
    {
        foreach ($permissionsByRole as $role => $permissions) {
            $roleName = (string) $role;
            if (trim($roleName) === '') {
                throw new InvalidArgumentException('Permission role names must be non-empty strings.');
            }

            if (!is_array($permissions)) {
                throw new InvalidArgumentException(sprintf('Permissions for role "%s" must be an array.', $roleName));
            }

            foreach ($permissions as $permission) {
                if (!is_string($permission) || trim($permission) === '') {
                    throw new InvalidArgumentException(
                        sprintf('Permission names for role "%s" must be non-empty strings.', $roleName)
                    );
                }
            }
        }

        $this->permissionsByRole = $permissionsByRole;
    }

    /**
     * @param list<string> $roles
     */
    public function grantsAny(array $roles, string $permission): bool
    {
        if (trim($permission) === '') {
            throw new InvalidArgumentException('Permission name must not be empty.');
        }

        foreach ($roles as $role) {
            if (in_array($permission, $this->permissionsByRole[$role] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}
