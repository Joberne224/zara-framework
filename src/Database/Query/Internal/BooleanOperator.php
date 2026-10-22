<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * The connector joining a condition to the ones before it in a WHERE/HAVING
 * chain. The first condition in a chain never uses its value (there is
 * nothing before it to connect to) — ConditionCompiler is responsible for
 * dropping it there.
 */
enum BooleanOperator: string
{
    case And = 'AND';
    case Or = 'OR';
}