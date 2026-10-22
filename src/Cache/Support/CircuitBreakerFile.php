<?php

declare(strict_types=1);

namespace Zara\Cache\Support;

/**
 * Persists circuit-breaker state to a marker file so it survives across
 * PHP-FPM requests. Pure in-memory state would reset on every request
 * (each request is typically a fresh process), which would defeat the
 * purpose: every request would still pay a full Redis connection
 * timeout while Redis is down.
 *
 * The marker file just holds a single Unix timestamp: "don't retry the
 * primary before this time."
 */
final class CircuitBreakerFile
{
    public function __construct(
        private readonly string $markerPath,
        private readonly int $cooldownSeconds = 30,
    ) {
        if (trim($this->markerPath) === '') {
            throw new \InvalidArgumentException('Circuit breaker marker path cannot be empty.');
        }

        if ($this->cooldownSeconds < 1) {
            throw new \InvalidArgumentException('Circuit breaker cooldown must be at least one second.');
        }
    }

    public function isOpen(): bool
    {
        $openUntil = @file_get_contents($this->markerPath);

        if ($openUntil === false) {
            return false;
        }

        return (int) $openUntil > time();
    }

    public function trip(): void
    {
        $directory = dirname($this->markerPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        @file_put_contents($this->markerPath, (string) (time() + $this->cooldownSeconds), LOCK_EX);
    }

    public function reset(): void
    {
        if (is_file($this->markerPath)) {
            @unlink($this->markerPath);
        }
    }
}
