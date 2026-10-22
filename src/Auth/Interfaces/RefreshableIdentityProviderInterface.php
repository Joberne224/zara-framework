<?php

declare(strict_types=1);

namespace Zara\Auth\Interfaces;

interface RefreshableIdentityProviderInterface extends IdentityProviderInterface
{
    public function refreshIdentity(IdentityInterface $identity): ?IdentityInterface;
}
