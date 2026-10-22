<?php

declare(strict_types=1);

namespace Zara\Core\Http\Internal;

use Zara\Core\Http\SessionConfig;

/**
 * Low-level contract for session data storage.
 *
 * @internal Decouples the Session facade from PHP's native session functions
 *           (session_start, $_SESSION, etc.), so that a native implementation
 *           can be used in production and an in-memory one in unit tests.
 *
 *           Every implementation must behave identically on the points below,
 *           otherwise tests run against the in-memory storage stop being
 *           representative of production:
 *
 *           - data operations (get/set/has/remove/all/clear/getId) throw a
 *             RuntimeException when the storage is not active;
 *           - regenerate() changes the session ID but ALWAYS preserves the data,
 *             whatever the value of $deleteOldSession (which only concerns the
 *             previous persisted session record).
 */
interface SessionStorageInterface
{
    /**
     * Tells whether the session storage is currently active.
     */
    public function isActive(): bool;

    /**
     * Starts the session storage with the given settings.
     *
     * Does nothing if the storage is already active.
     *
     * @param SessionConfig $config Validated session settings (cookie
     *        parameters, name, save path, strict mode, GC lifetime).
     *
     * @throws \RuntimeException If the storage cannot be started.
     */
    public function start(SessionConfig $config): void;

    /**
     * Regenerates the session ID, keeping the session data.
     *
     * @param bool $deleteOldSession If true, the previous session record is
     *        deleted from the underlying store. Session data is kept either way.
     *
     * @throws \RuntimeException If the storage is not active or the ID cannot
     *                           be regenerated.
     */
    public function regenerate(bool $deleteOldSession): void;

    /**
     * Destroys the session storage (data and cookie).
     *
     * @throws \RuntimeException If the storage is not active or cannot be destroyed.
     */
    public function destroy(): void;

    /**
     * Writes the session data and closes the storage, releasing any lock.
     *
     * Does nothing if the storage is not active. Data is kept: starting the
     * storage again resumes the same session.
     *
     * @throws \RuntimeException If the session cannot be written.
     */
    public function save(): void;

    /**
     * Returns the current session ID.
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function getId(): string;

    /**
     * Returns a value by key as a `[exists, value]` pair. The first element
     * makes it possible to tell a stored `null` from a missing key.
     *
     * @return array{0: bool, 1: mixed}
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function get(string $key): array;

    /**
     * Sets a value for the given key.
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Tells whether a key exists. Unlike `isset`, a stored `null` counts as present.
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function has(string $key): bool;

    /**
     * Removes a key.
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function remove(string $key): void;

    /**
     * Returns all session data as an associative array.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function all(): array;

    /**
     * Removes all session data without destroying the session itself.
     *
     * @throws \RuntimeException If the storage is not active.
     */
    public function clear(): void;
}