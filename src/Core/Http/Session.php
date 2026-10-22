<?php
// Zara/Core/Http/Session.php
declare(strict_types=1);

namespace Zara\Core\Http;

use InvalidArgumentException;
use RuntimeException;
use Zara\Core\Http\Internal\FlashBag;
use Zara\Core\Http\Internal\NativeSessionStorage;
use Zara\Core\Http\Internal\SessionKeyValidator;
use Zara\Core\Http\Internal\SessionStorageInterface;
use Zara\Core\Http\Interfaces\SessionInterface;

/**
 * Object-oriented wrapper around PHP sessions.
 *
 * Facade: all low-level logic is delegated to classes in the `Internal\`
 * sub-namespace (same decomposition model as Router):
 *
 *   - SessionConfig                    : validated, immutable settings
 *   - Internal\SessionStorageInterface : storage contract (injectable)
 *   - Internal\NativeSessionStorage    : real implementation ($_SESSION)
 *   - Internal\ArraySessionStorage     : in-memory implementation (tests)
 *   - Internal\FlashBag                : flash message lifecycle
 *   - Internal\SessionKeyValidator     : key validation
 *
 * The coupling to native PHP functions is not removed, it is relocated:
 * explicit, isolated in NativeSessionStorage, and injectable.
 *
 * One instance is expected per request: the flash generations are rotated
 * once per instance, the first time the session becomes available.
 */
final class Session implements SessionInterface
{
    private SessionConfig $config;
    private SessionStorageInterface $storage;
    private SessionKeyValidator $validator;
    private FlashBag $flash;
    private bool $flashRotated = false;

    /**
     * @param SessionConfig|null $config Session settings. When omitted,
     *        SessionConfig::fromArray([]) is used, i.e. the defaults (a bare
     *        `new SessionConfig()` is equivalent). Every default and
     *        resolution rule, including the HTTPS detection of "secure",
     *        lives in SessionConfig.
     * @param SessionStorageInterface|null $storage Storage implementation.
     *        Defaults to NativeSessionStorage (production). Injecting an
     *        ArraySessionStorage allows testing Session without a real HTTP runtime.
     *
     * @throws InvalidArgumentException If the default configuration cannot be built.
     */
    public function __construct(?SessionConfig $config = null, ?SessionStorageInterface $storage = null)
    {
        $this->config = $config ?? SessionConfig::fromArray([]);
        $this->storage = $storage ?? new NativeSessionStorage();
        $this->validator = new SessionKeyValidator();
        $this->flash = new FlashBag($this->storage, $this->validator);
    }

    /**
     * Starts the session if it is not already active.
     *
     * Also rotates the flash generations, once per Session instance, even when
     * the underlying session was already started by someone else.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function start(): void
    {
        if (!$this->storage->isActive()) {
            $this->storage->start($this->config);
        }

        if (!$this->flashRotated) {
            $this->flash->rotate();
            $this->flashRotated = true;
        }
    }

    /**
     * Stores a value in the session.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function set(string $key, mixed $value): void
    {
        $this->start();
        $this->validator->validate($key);
        $this->storage->set($key, $value);
    }

    /**
     * Returns a session value, or $default if the key does not exist.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        $this->validator->validate($key);

        [$exists, $value] = $this->storage->get($key);

        return $exists ? $value : $default;
    }

    /**
     * Tells whether a key exists in the session.
     *
     * Uses a real key existence check (not `isset`), so a value explicitly
     * stored as `null` is still considered present.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function has(string $key): bool
    {
        $this->start();
        $this->validator->validate($key);

        return $this->storage->has($key);
    }

    /**
     * Removes a key from the session.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function remove(string $key): void
    {
        $this->start();
        $this->validator->validate($key);
        $this->storage->remove($key);
    }

    /**
     * Returns a value and removes it from the session, or $default if the key does not exist.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $this->start();
        $this->validator->validate($key);

        [$exists, $value] = $this->storage->get($key);

        if (!$exists) {
            return $default;
        }

        $this->storage->remove($key);

        return $value;
    }

    /**
     * Returns all application data stored in the session (reserved keys excluded).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function all(): array
    {
        $this->start();

        return array_filter(
            $this->storage->all(),
            fn (int|string $key): bool => !$this->validator->isReserved((string) $key),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Removes all application data from the session without destroying it.
     * Reserved entries, hence pending flash messages, are kept.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function clear(): void
    {
        foreach (array_keys($this->all()) as $key) {
            $this->storage->remove((string) $key);
        }
    }

    /**
     * Destroys the session.
     *
     * @throws RuntimeException If the session cannot be destroyed.
     */
    public function destroy(): void
    {
        if (!$this->storage->isActive()) {
            return;
        }

        $this->storage->destroy();
    }

    /**
     * Regenerates the session ID, keeping the session data.
     *
     * @throws RuntimeException If the ID cannot be regenerated.
     */
    public function regenerate(): void
    {
        $this->start();
        $this->storage->regenerate(true);
    }

    /**
     * Writes the session data and closes the session, releasing the storage lock.
     * Accessing the session afterwards reopens it.
     *
     * @throws RuntimeException If the session data cannot be written.
     */
    public function save(): void
    {
        if (!$this->storage->isActive()) {
            return;
        }

        $this->storage->save();
    }

    /**
     * Returns the current session ID.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function getId(): string
    {
        $this->start();

        return $this->storage->getId();
    }

    /**
     * Stores a flash message, available from the next request.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function setFlash(string $key, mixed $value): void
    {
        $this->start();
        $this->flash->set($key, $value);
    }

    /**
     * Returns a flash message from the previous request and removes it.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $this->flash->get($key, $default);
    }

    /**
     * Tells whether a flash message from the previous request exists, without consuming it.
     *
     * @throws InvalidArgumentException If the key is invalid or reserved.
     * @throws RuntimeException If the session cannot be started.
     */
    public function hasFlash(string $key): bool
    {
        $this->start();

        return $this->flash->has($key);
    }

    /**
     * Tells whether the session is active.
     */
    public function isStarted(): bool
    {
        return $this->storage->isActive();
    }
}