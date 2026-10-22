<?php
declare(strict_types=1);

namespace Zara\Auth\Interfaces;

interface IdentityInterface
{
    public function getId(): int|string;

    /** @return string[] */
    public function getRoles(): array;
}