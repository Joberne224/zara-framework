<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * The shape of a single WHERE/HAVING condition.
 *
 * Kept as a closed enum rather than a free-form "type" string so that
 * ConditionCompiler's match() over it is exhaustive: adding a new case here
 * without teaching the compiler about it is a compile-time error, not a
 * silent runtime gap.
 */
enum ConditionType
{
    case Basic;
    case In;
    case NotIn;
    case IsNull;
    case IsNotNull;
    case Between;
    case Raw;
    case Group;
}