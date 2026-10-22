<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * A raw SQL fragment paired with its bound parameters, in the exact order
 * they must be passed alongside it. This is the only shape that crosses the
 * boundary back out of the Internal namespace into QueryBuilder.
 */
final class CompiledQuery
{
    /**
     * @param mixed[] $params
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params,
    ) {
    }
}