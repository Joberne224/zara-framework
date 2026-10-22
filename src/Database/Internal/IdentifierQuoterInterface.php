<?php

declare(strict_types=1);

namespace Zara\Database\Internal;

interface IdentifierQuoterInterface
{
    public function quote(string $identifier): string;
}