<?php

declare(strict_types=1);

namespace Zara\Core\Storage\Exceptions;

class PathTraversalException extends StorageException
{
    public function __construct(string $path)
    {
        return parent::__construct(sprintf('Path traversal attempt detected: "%s"', $path));
    }
}