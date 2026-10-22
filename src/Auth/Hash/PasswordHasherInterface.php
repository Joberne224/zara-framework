<?php
declare(strict_types=1);

namespace Zara\Auth\Hash;

interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hashedPassword): bool;
}