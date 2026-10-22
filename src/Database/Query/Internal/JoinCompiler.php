<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

final class JoinCompiler
{
    public function __construct(private readonly QualifiedIdentifierQuoter $identifiers)
    {
    }

    /**
     * @param array<int, JoinClause|RawJoin> $joins
     */
    public function compile(array $joins): CompiledQuery
    {
        $fragments = [];
        $params = [];

        foreach ($joins as $join) {
            if ($join instanceof RawJoin) {
                $fragments[] = $join->sql;
                array_push($params, ...$join->params);
                continue;
            }

            $fragments[] = sprintf(
                '%s JOIN %s ON %s %s %s',
                $join->type->value,
                $this->identifiers->quote($join->table),
                $this->identifiers->quote($join->first),
                $join->operator,
                $this->identifiers->quote($join->second)
            );
        }

        return new CompiledQuery(implode(' ', $fragments), $params);
    }
}