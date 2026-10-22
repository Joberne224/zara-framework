<?php
declare(strict_types=1);

namespace Zara\Cache;

use Zara\Cache\Exception\InvalidArgumentException;

final class CacheKeyValidator
{
    private const RESERVED_CHARACTERS = '{}()/\@:';

    public function validate(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key cannot be an empty string.');
        }

        if (strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new InvalidArgumentException(
                sprintf('Cache key "%s" contains a PSR-16 reserved character (%s).', $key, self::RESERVED_CHARACTERS)
            );
        }
    }

    /** @param iterable<mixed> $keys */
    public function validateAll(iterable $keys): void
    {
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('All keys must be strings.');
            }
            $this->validate($key);
        }
    }
}