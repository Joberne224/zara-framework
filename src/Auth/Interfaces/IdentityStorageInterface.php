<?php
declare(strict_types=1);

namespace Zara\Auth\Interfaces;

interface IdentityStorageInterface
{
    public function set(IdentityInterface $identity): void;

    public function get(): ?IdentityInterface;

    public function clear(): void;
}