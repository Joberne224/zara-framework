<?php
declare(strict_types=1);

namespace Zara\Auth\Hash;

use InvalidArgumentException;
use RuntimeException;

final class NativePasswordHasher implements RehashingPasswordHasherInterface
{
    private const MAX_PASSWORD_BYTES = 4096;
    private const BCRYPT_MAX_PASSWORD_BYTES = 72;

    public function __construct(
        private readonly string|int|null $algo = PASSWORD_BCRYPT,
        private readonly array $options = ['cost' => 12],
    ) {
    }

    public function hash(string $plainPassword): string
    {
        $this->assertPasswordSize($plainPassword);

        if ($this->usesBcrypt() && strlen($plainPassword) > self::BCRYPT_MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Bcrypt passwords cannot exceed 72 bytes.');
        }

        $hash = password_hash($plainPassword, $this->algo, $this->options);
        if (!is_string($hash)) {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public function verify(string $plainPassword, string $hashedPassword): bool
    {
        if (strlen($plainPassword) > self::MAX_PASSWORD_BYTES
            || ($this->usesBcrypt() && strlen($plainPassword) > self::BCRYPT_MAX_PASSWORD_BYTES)) {
            return false;
        }

        return password_verify($plainPassword, $hashedPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return password_needs_rehash($hashedPassword, $this->algo, $this->options);
    }

    private function assertPasswordSize(string $plainPassword): void
    {
        if (strlen($plainPassword) > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('Password input exceeds the maximum supported size.');
        }
    }

    private function usesBcrypt(): bool
    {
        return $this->algo === PASSWORD_BCRYPT || $this->algo === PASSWORD_DEFAULT;
    }
}
