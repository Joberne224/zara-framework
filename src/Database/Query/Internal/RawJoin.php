<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * A raw JOIN fragment produced by joinRaw(), emitted as-is between FROM and
 * WHERE — the fragment includes its own JOIN keyword and ON clause. The
 * caller owns injection safety for $sql; only $params are bound. Exists
 * alongside the structured JoinClause because composite ON conditions and
 * table aliases are not expressible through the typed join() API.
 */
final class RawJoin
{
    /**
     * @param mixed[] $params
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
    ) {
        if (trim($sql) === '') {
            throw new \InvalidArgumentException('joinRaw() requires a non-empty SQL fragment.');
        }
    }
}
