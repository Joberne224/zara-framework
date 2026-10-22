<?php
declare(strict_types=1);

namespace Zara\Database;

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

    public function reveal(): string
    {
        return self::$values[$this];
    }

    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    public function __toString(): string
    {
        throw new \LogicException(
            sprintf('%s cannot be cast to string. Call reveal() explicitly to obtain the real value.', self::class)
        );
    }

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
