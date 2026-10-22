<?php

declare(strict_types=1);

namespace Zara\Auth\Hash;

interface RehashingPasswordHasherInterface extends PasswordHasherInterface
{
    public function needsRehash(string $hashedPassword): bool;
}
