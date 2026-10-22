<?php

declare(strict_types=1);

namespace Zara\Core\Http\Internal;

use RuntimeException;
use Zara\Core\Http\SessionConfig;

/**
 * Session storage based on PHP's native session mechanisms
 * ($_SESSION, session_start, session_regenerate_id...).
 *
 * @internal PHP natively serializes the whole of $_SESSION when the session is
 *           written, so values must never be serialized by hand: arrays,
 *           objects and scalars (including `null`, `false`, `0` and empty
 *           strings) are stored and restored faithfully.
 *
 *           Settings are applied right before every session start. Two
 *           directives are always forced, whatever the configuration:
 *           use_only_cookies (never accept a session ID from the URL) and
 *           use_trans_sid=0 (never append the session ID to URLs).
 */
final class NativeSessionStorage implements SessionStorageInterface
{
    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function start(SessionConfig $config): void
    {
        if ($this->isActive()) {
            return;
        }

        if (headers_sent($file, $line)) {
            throw new RuntimeException(sprintf(
                'Cannot start session: headers already sent (output started at %s:%d).',
                (string) $file,
                (int) $line
            ));
        }

        // use_strict_mode rejects session IDs the server did not issue
        // (protects against session fixation); it is on unless disabled.
        $this->applyIni('session.use_strict_mode', $config->strictMode ? '1' : '0');
        $this->applyIni('session.use_only_cookies', '1');
        $this->applyIni('session.use_trans_sid', '0');

        if ($config->gcMaxLifetime !== null) {
            $this->applyIni('session.gc_maxlifetime', (string) $config->gcMaxLifetime);
        }

        if ($config->name !== null && session_name($config->name) === false) {
            throw new RuntimeException(sprintf('Failed to set session name "%s".', $config->name));
        }

        if ($config->savePath !== null && session_save_path($config->savePath) === false) {
            throw new RuntimeException(sprintf('Failed to set session save path "%s".', $config->savePath));
        }

        if (!session_set_cookie_params($config->cookieParams())) {
            throw new RuntimeException('Failed to apply session cookie parameters.');
        }

        if (!session_start()) {
            throw new RuntimeException('Failed to start session.');
        }
    }

    public function regenerate(bool $deleteOldSession): void
    {
        $this->assertActive();

        // session_regenerate_id() keeps the content of $_SESSION: only the ID
        // changes (and, optionally, the old session record is deleted).
        if (!session_regenerate_id($deleteOldSession)) {
            throw new RuntimeException('Failed to regenerate session ID.');
        }
    }

    public function destroy(): void
    {
        $this->assertActive();

        $name = session_name();
        $params = session_get_cookie_params();

        $_SESSION = [];

        if (!session_destroy()) {
            throw new RuntimeException('Failed to destroy session.');
        }

        // The cookie can only be expired while headers are still open. If they
        // are not, the server-side session is already destroyed and (with
        // strict mode) the orphaned ID is simply ignored on the next request.
        if (ini_get('session.use_cookies') && !headers_sent()) {
            setcookie((string) $name, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }
    }

    public function save(): void
    {
        if (!$this->isActive()) {
            return;
        }

        if (!session_write_close()) {
            throw new RuntimeException('Failed to write session data.');
        }
    }

    public function getId(): string
    {
        $this->assertActive();

        return (string) session_id();
    }

    public function get(string $key): array
    {
        if (!$this->has($key)) {
            return [false, null];
        }

        return [true, $_SESSION[$key]];
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertActive();

        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->assertActive();

        // array_key_exists() rather than isset(): an explicitly stored `null`
        // must be considered present.
        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        $this->assertActive();

        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        $this->assertActive();

        return $_SESSION;
    }

    public function clear(): void
    {
        $this->assertActive();

        $_SESSION = [];
    }

    /**
     * @throws RuntimeException If the directive cannot be changed.
     */
    private function applyIni(string $directive, string $value): void
    {
        if (ini_set($directive, $value) === false) {
            throw new RuntimeException(sprintf('Cannot apply session directive "%s".', $directive));
        }
    }

    /**
     * @throws RuntimeException If the session is not active.
     */
    private function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new RuntimeException('Session is not active. Start it before accessing its data.');
        }
    }
}