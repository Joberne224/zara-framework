<?php

declare(strict_types=1);

namespace Zara\Core\Http\Internal;

use InvalidArgumentException;

/**
 * Validates the session keys used by Session and FlashBag.
 *
 * @internal Keys starting with RESERVED_PREFIX are reserved for the framework
 *           (e.g. the flash bag storage key) and cannot be written through the
 *           public Session API, so application code can never overwrite
 *           internal state by accident.
 */
final class SessionKeyValidator
{
    public const RESERVED_PREFIX = '__';

    // \z rather than $: "$" also matches before a trailing newline,
    // which would let "key\n" through.
    private const VALID_KEY_PATTERN = '/^[a-zA-Z0-9_\-.]+\z/';

    /**
     * @throws InvalidArgumentException If the key is invalid or reserved.
     */
    public function validate(string $key): void
    {
        if (preg_match(self::VALID_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "Invalid session key: '%s'. Must match pattern %s.",
                $key,
                self::VALID_KEY_PATTERN
            ));
        }

        if ($this->isReserved($key)) {
            throw new InvalidArgumentException(sprintf(
                "Invalid session key: '%s'. Keys starting with '%s' are reserved for internal use.",
                $key,
                self::RESERVED_PREFIX
            ));
        }
    }

    /**
     * Tells whether a key belongs to the framework's reserved namespace.
     */
    public function isReserved(string $key): bool
    {
        return str_starts_with($key, self::RESERVED_PREFIX);
    }
}