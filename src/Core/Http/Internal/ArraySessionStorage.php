<?php

declare(strict_types=1);

namespace Zara\Core\Http\Internal;

use RuntimeException;
use Zara\Core\Http\SessionConfig;

/**
 * In-memory session storage.
 *
 * @internal Implements SessionStorageInterface without touching PHP's native
 *           session functions. Meant for unit tests of Session and of any class
 *           depending on it, without a real HTTP runtime or header handling.
 *
 *           It mirrors the behavior of NativeSessionStorage: data operations
 *           fail when the storage is not active, and regenerate() changes the
 *           ID while keeping the data. Data survives save() (close) so that a
 *           test can simulate several requests on the same storage instance
 *           by calling save() and then building a new Session around it.
 */
final class ArraySessionStorage implements SessionStorageInterface
{
    /** @var array<string, mixed> */
    private array $data = [];
    private bool $active = false;
    private string $id = '';
    private ?SessionConfig $config = null;

    public function isActive(): bool
    {
        return $this->active;
    }

    public function start(SessionConfig $config): void
    {
        if ($this->active) {
            return;
        }

        $this->config = $config;
        $this->active = true;

        if ($this->id === '') {
            $this->id = $this->newId();
        }
    }

    public function regenerate(bool $deleteOldSession): void
    {
        $this->assertActive();

        // Data is always preserved, exactly like session_regenerate_id().
        $this->id = $this->newId();
    }

    public function destroy(): void
    {
        $this->assertActive();

        $this->data = [];
        $this->id = '';
        $this->active = false;
    }

    public function save(): void
    {
        $this->active = false;
    }

    public function getId(): string
    {
        $this->assertActive();

        return $this->id;
    }

    public function get(string $key): array
    {
        if (!$this->has($key)) {
            return [false, null];
        }

        return [true, $this->data[$key]];
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertActive();

        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->assertActive();

        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        $this->assertActive();

        unset($this->data[$key]);
    }

    public function all(): array
    {
        $this->assertActive();

        return $this->data;
    }

    public function clear(): void
    {
        $this->assertActive();

        $this->data = [];
    }

    /**
     * Returns the settings received by the last start() call, or null if the
     * storage was never started. Test helper: lets a test assert the
     * configuration reaching the storage.
     */
    public function getConfig(): ?SessionConfig
    {
        return $this->config;
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @throws RuntimeException If the storage is not active.
     */
    private function assertActive(): void
    {
        if (!$this->active) {
            throw new RuntimeException('Session is not active. Start it before accessing its data.');
        }
    }
}