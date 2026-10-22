<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

enum JoinType: string
{
    case Inner = 'INNER';
    case Left = 'LEFT';
    case Right = 'RIGHT';
}