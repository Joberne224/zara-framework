<?php

declare(strict_types=1);

namespace Zara\Database\Internal;

interface PdoProviderInterface
{
    public function getPdo(): \PDO;

    /** Safe connection metadata; never include credentials or DSNs. */
    public function getProfilerInfo(): array;
}
