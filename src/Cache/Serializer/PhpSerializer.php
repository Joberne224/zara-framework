<?php
// Zara\Cache\Serializer\PhpSerializer.php
declare(strict_types=1);

namespace Zara\Cache\Serializer;

final class PhpSerializer implements CacheSerializerInterface
{
    public function serialize(mixed $value): string
    {
        return serialize($value);
    }

    public function unserialize(string $payload): mixed
    {
        return unserialize($payload);
    }
}