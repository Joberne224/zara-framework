<?php

declare(strict_types=1);

namespace Zara\Core\Support;

/**
 * Opaque wrapper for a sensitive scalar value (password, API token, secret...).
 *
 * The wrapped value is never stored as an object property. It lives in a
 * private static WeakMap keyed by the wrapper instance. Because there is no
 * property to reflect over, print_r(), var_dump(), var_export() all show an
 * empty object body regardless of visibility rules — there is nothing for
 * them to expose.
 *
 * The real value is retrievable only through one explicit, intention-revealing
 * call: reveal(). Anything that tries to obtain it implicitly — casting to
 * string, serializing — fails loudly instead of silently handing back a mask
 * that a caller could mistake for real data (e.g. sending the literal string
 * "******" to a database as the password).
 */
final class SensitiveValue
{
    private const MASK = '******';

    /** @var \WeakMap<self, string> */
    private static \WeakMap $values;

    public function __construct(string $value)
    {
        self::$values ??= new \WeakMap();
        self::$values[$this] = $value;
    }

    /**
     * The only sanctioned way to obtain the real value.
     */
    public function reveal(): string
    {
        return self::$values[$this];
    }

    /**
     * Controls var_dump()/dump() output. Safe to mask (rather than throw)
     * because nothing consumes this return value as data — it exists purely
     * for human inspection.
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /**
     * Deliberately throws instead of masking: a string cast is a data path,
     * not an inspection path. Silently returning a mask here would let the
     * masked value leak into real usage (DSNs, headers, logs built via
     * string interpolation) without any error to signal the mistake.
     */
    public function __toString(): string
    {
        throw new \LogicException(
            sprintf('%s cannot be cast to string. Call reveal() explicitly to obtain the real value.', self::class)
        );
    }

    /**
     * Deliberately throws instead of silently persisting a mask: a config
     * cache or session file containing "******" as a password would fail
     * confusingly later, far from this line. Forcing an explicit decision
     * here (unwrap via reveal() and handle it yourself) keeps the failure
     * loud and local.
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            sprintf(
                '%s cannot be serialized. Call reveal() explicitly and handle the real value yourself if serialization is genuinely required.',
                self::class
            )
        );
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException(sprintf('%s cannot be unserialized.', self::class));
    }
}