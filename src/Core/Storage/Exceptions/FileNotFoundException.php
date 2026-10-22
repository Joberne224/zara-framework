<?php

declare(strict_types=1);

namespace Zara\Core\Storage\Exceptions;

class FileNotFoundException extends StorageException 
{
    public function __construct(string $path)
    {
        return parent::__construct(sprintf('File not found: "%s"', $path));
    }
}