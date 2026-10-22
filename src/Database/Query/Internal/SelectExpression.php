<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * One entry in a SELECT column list. Plain identifiers are quoted at compile
 * time (including the "AS alias" support in SelectCompiler); raw fragments
 * come from selectRaw() and are emitted as-is — the caller owns injection
 * safety for them, only $params are bound.
 */
final class SelectExpression
{
    /**
     * @param mixed[] $params
     */
    private function __construct(
        public readonly string $sql,
        public readonly bool $raw,
        public readonly array $params,
    ) {
    }

    public static function column(string $column): self
    {
        return new self($column, false, []);
    }

    /**
     * @param mixed[] $params
     */
    public static function raw(string $sql, array $params = []): self
    {
        if (trim($sql) === '') {
            throw new \InvalidArgumentException('selectRaw() requires a non-empty SQL fragment.');
        }

        return new self($sql, true, array_values($params));
    }
}
