<?php
// file: Zara/Core/Http/Interfaces/FileCollectionInterface.php

declare(strict_types=1);

namespace Zara\Core\Files\Interfaces;
use Zara\Core\Files\Interfaces\FileInterface;

interface FileCollectionInterface extends \Countable, \IteratorAggregate
{
    /**
     * Retrieves a file or a nested collection by key.
     */
    public function get(string $key): FileInterface|FileCollectionInterface|null;

    /**
     * Returns all files in the collection as an array.
     */
    public function all(): array;

    /**
     * Adds a file to the collection.
     */
    public function addFile(FileInterface $file): FileCollectionInterface;
}