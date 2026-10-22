<?php
declare(strict_types=1);

namespace Zara\Core\Storage;

final class StorageManager
{
    public function __construct(private readonly DiskInterface $disk) {}

    public function disk(): DiskInterface
    {
        return $this->disk;
    }
}