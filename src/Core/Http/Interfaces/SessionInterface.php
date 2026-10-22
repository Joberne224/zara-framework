<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

use InvalidArgumentException;
use RuntimeException;

interface SessionInterface
{
    /**
     * Starts the session if it is not already active.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function start(): void;

    /**
     * Stores a value in the session.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Returns a session value, or $default if the key does not exist.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Tells whether a key exists, even if its value is null.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function has(string $key): bool;

    /**
     * Removes a key from the session.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function remove(string $key): void;

    /**
     * Returns a value and removes it from the session, or $default if the key does not exist.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * Returns all application data stored in the session.
     *
     * Framework-internal entries (reserved keys such as the flash bag) are not included.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function all(): array;

    /**
     * Removes all application data from the session without destroying it.
     *
     * Pending flash messages are kept.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function clear(): void;

    /**
     * Destroys the session.
     *
     * @throws RuntimeException If the session cannot be destroyed.
     */
    public function destroy(): void;

    /**
     * Regenerates the session ID, keeping the session data.
     *
     * Call it on every privilege change (login, logout, role switch).
     *
     * @throws RuntimeException If the ID cannot be regenerated.
     */
    public function regenerate(): void;

    /**
     * Writes the session data and closes the session, releasing the storage lock.
     *
     * Useful before long-running work, so concurrent requests of the same user
     * are not blocked. Accessing the session afterwards reopens it.
     * Does nothing if the session is not active.
     *
     * @throws RuntimeException If the session data cannot be written.
     */
    public function save(): void;

    /**
     * Returns the current session ID.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function getId(): string;

    /**
     * Stores a flash message, available from the next request.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function setFlash(string $key, mixed $value): void;

    /**
     * Returns a flash message from the previous request and removes it.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function getFlash(string $key, mixed $default = null): mixed;

    /**
     * Tells whether a flash message from the previous request exists, without consuming it.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function hasFlash(string $key): bool;

    /**
     * Tells whether the session is active.
     */
    public function isStarted(): bool;
}