<?php
declare(strict_types=1);

namespace Zara\Auth\Interfaces;

interface CredentialsRepositoryInterface
{
    /**
     * Finds a user by the field used to log in (email, username, ...).
     */
    public function findByIdentifier(string $identifier): ?object;
}