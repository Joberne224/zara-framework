<?php

declare(strict_types=1);

namespace Zara\Core\Http\Internal;

/**
 * Manages the lifecycle of flash messages using a two-generation model
 * ("current" / "next"), like Symfony or Laravel.
 *
 * @internal Why two generations?
 *           A flash set during request A must survive until request B
 *           (typically after a redirect), then disappear. With a single
 *           generation, a cleanup performed when the session starts (before
 *           application code runs) would delete the flash before it could be
 *           read, so it would never survive a redirect.
 *
 *           Here, setFlash() during request A writes into "next". When
 *           request B starts, rotate() promotes "next" (written in A) to
 *           "current" and empties "next". get() in B reads "current" and
 *           consumes the key (read-once).
 *
 *           The bag lives under a reserved key (see SessionKeyValidator), so
 *           it cannot be overwritten through the public Session API.
 */
final class FlashBag
{
    private const STORAGE_KEY = '__flash';

    private SessionStorageInterface $storage;
    private SessionKeyValidator $validator;

    public function __construct(SessionStorageInterface $storage, SessionKeyValidator $validator)
    {
        $this->storage = $storage;
        $this->validator = $validator;
    }

    /**
     * Rotates the generations. Must be called once per request, when the
     * session becomes available.
     */
    public function rotate(): void
    {
        $bag = $this->readBag();
        $bag['current'] = $bag['next'];
        $bag['next'] = [];
        $this->writeBag($bag);
    }

    /**
     * Sets a flash message, available from the next request.
     */
    public function set(string $key, mixed $value): void
    {
        $this->validator->validate($key);
        $bag = $this->readBag();
        $bag['next'][$key] = $value;
        $this->writeBag($bag);
    }

    /**
     * Returns a flash message from the current generation and consumes it (read-once).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validator->validate($key);
        $bag = $this->readBag();

        if (!array_key_exists($key, $bag['current'])) {
            return $default;
        }

        $value = $bag['current'][$key];
        unset($bag['current'][$key]);
        $this->writeBag($bag);

        return $value;
    }

    /**
     * Tells whether a flash message exists in the current generation, without consuming it.
     */
    public function has(string $key): bool
    {
        $this->validator->validate($key);

        return array_key_exists($key, $this->readBag()['current']);
    }

    /**
     * @return array{current: array<string, mixed>, next: array<string, mixed>}
     */
    private function readBag(): array
    {
        [$exists, $value] = $this->storage->get(self::STORAGE_KEY);

        // A flash bag is disposable by nature: anything that is not a
        // well-formed bag is treated as an empty one.
        if (!$exists || !is_array($value)) {
            return ['current' => [], 'next' => []];
        }

        return [
            'current' => is_array($value['current'] ?? null) ? $value['current'] : [],
            'next' => is_array($value['next'] ?? null) ? $value['next'] : [],
        ];
    }

    /**
     * @param array{current: array<string, mixed>, next: array<string, mixed>} $bag
     */
    private function writeBag(array $bag): void
    {
        $this->storage->set(self::STORAGE_KEY, $bag);
    }
}