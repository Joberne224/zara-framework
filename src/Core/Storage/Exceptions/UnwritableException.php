<?php

declare(strict_types=1);

namespace Zara\Core\Storage\Exceptions;

class UnwritableException extends StorageException
{
    public function __construct(string $path)
    {
        return parent::__construct(sprintf('Cannot write to path: "%s"', $path));
    }
}