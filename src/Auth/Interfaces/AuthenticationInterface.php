<?php
declare(strict_types=1);

namespace Zara\Auth\Interfaces;

interface AuthenticationInterface
{
    public function authenticate(string $identifier, string $password): bool;

    public function check(): bool;

    public function user(): ?IdentityInterface;

    public function logout(): void;
}