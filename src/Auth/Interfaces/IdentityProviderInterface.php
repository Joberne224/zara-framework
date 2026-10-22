<?php
declare(strict_types=1);

namespace Zara\Auth\Interfaces;

interface IdentityProviderInterface
{
    public function retrieveByCredentials(string $identifier, string $password): ?IdentityInterface;
}