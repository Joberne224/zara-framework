<?php
declare(strict_types=1);

namespace Zara\Auth;

use Zara\Auth\Interfaces\IdentityInterface;

final class GenericIdentity implements IdentityInterface
{
    /** @param string[] $roles */
    public function __construct(
        private readonly int|string $id,
        private readonly array $roles = [],
    ) {
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }
}