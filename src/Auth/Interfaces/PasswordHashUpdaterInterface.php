<?php

declare(strict_types=1);

namespace Zara\Auth\Interfaces;

/** Optional repository capability for upgrading a password hash after login. */
interface PasswordHashUpdaterInterface
{
    public function updatePasswordHash(int|string $id, string $passwordHash): void;
}
