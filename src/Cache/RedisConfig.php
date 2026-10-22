<?php
declare(strict_types=1);

namespace Zara\Cache;

final class RedisConfig
{
    public function __construct(
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 6379,
        public readonly ?string $password = null,
        public readonly int $database = 0,
        public readonly float $timeout = 2.0,
        public readonly string $keyPrefix = 'zara:',
    ) {
        if (trim($host) === '') {
            throw new \InvalidArgumentException('RedisConfig: host cannot be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("RedisConfig: invalid port ($port), must be between 1 and 65535.");
        }

        if ($database < 0) {
            throw new \InvalidArgumentException("RedisConfig: database cannot be negative ($database).");
        }

        if ($timeout <= 0) {
            throw new \InvalidArgumentException("RedisConfig: timeout must be strictly positive ($timeout).");
        }

        if ($keyPrefix === '' || preg_match('/^[A-Za-z0-9:_-]+$/D', $keyPrefix) !== 1) {
            throw new \InvalidArgumentException(
                'RedisConfig: keyPrefix must be non-empty and contain only letters, digits, colons, underscores, or hyphens.'
            );
        }
    }
}
