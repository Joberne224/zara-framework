<?php

declare(strict_types=1);

namespace Zara\Auth\Interfaces;

/** Optional repository capability for reloading an authenticated identity. */
interface IdentityLookupRepositoryInterface
{
    public function findById(int|string $id): ?object;
}
