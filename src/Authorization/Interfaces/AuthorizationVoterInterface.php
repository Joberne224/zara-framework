<?php
declare(strict_types=1);

namespace Zara\Authorization\Interfaces;

use Zara\Auth\Interfaces\IdentityInterface;

interface AuthorizationVoterInterface
{
    /**
     * Return null to abstain, true to grant, or false to deny.
     */
    public function vote(
        IdentityInterface $identity,
        string $permission,
        ?object $subject = null,
    ): ?bool;
}
