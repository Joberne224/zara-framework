<?php
declare(strict_types=1);

namespace Zara\Authorization\Interfaces;

use Zara\Auth\Interfaces\IdentityInterface;

interface AuthorizationInterface
{
    public function isGranted(
        IdentityInterface $identity,
        string $permission,
        ?object $subject = null,
    ): bool;

    /** @param list<string> $roles */
    public function hasAnyRole(IdentityInterface $identity, array $roles): bool;
}
