<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

enum SortDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}