<?php
// Zara\Cache\Serializer\CacheSerializerInterface.php
declare(strict_types=1);

namespace Zara\Cache\Serializer;

interface CacheSerializerInterface
{
    public function serialize(mixed $value): string;

    public function unserialize(string $payload): mixed;
}