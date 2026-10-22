<?php

declare(strict_types=1);

namespace Zara\Core\Http;

use InvalidArgumentException;

/**
 * Validated, immutable session settings.
 *
 * Cookie parameters go to session_set_cookie_params(); the other options
 * (name, save path, strict mode, garbage-collection lifetime) are applied by
 * the native storage right before the session starts.
 *
 * The constructor and fromArray() behave identically: every default and every
 * resolution rule below lives in the constructor, so building the object
 * directly is never less safe than going through an array.
 *
 * Resolved values:
 *  - secure: when null (the default), enabled if the request came over HTTPS.
 *    Behind a reverse proxy this detection is wrong: set it explicitly.
 *  - sameSite: case-insensitive; stored as "Strict", "Lax" or "None".
 *  - gcMaxLifetime: when null and lifetime > 0, set to the larger of lifetime
 *    and PHP's current session.gc_maxlifetime, so the server never discards a
 *    session while its cookie is still valid. An explicit value is kept as is
 *    (a shorter server-side lifetime is a legitimate idle timeout).
 */
final readonly class SessionConfig
{
    private const SAME_SITE_VALUES = ['Strict', 'Lax', 'None'];

    /** Expected type of each array option, as reported by get_debug_type(). */
    private const OPTION_TYPES = [
        'lifetime' => 'int',
        'path' => 'string',
        'domain' => 'string',
        'secure' => 'bool',
        'httponly' => 'bool',
        'samesite' => 'string',
        'name' => 'string',
        'save_path' => 'string',
        'strict_mode' => 'bool',
        'gc_maxlifetime' => 'int',
    ];

    // Resolved in the constructor, hence not promoted.
    public bool $secure;
    public string $sameSite;
    public ?int $gcMaxLifetime;

    public function __construct(
        public int $lifetime = 0,
        public string $path = '/',
        // Never derived from the Host header (client-controlled): an empty
        // domain lets the browser use the current one.
        public string $domain = '',
        ?bool $secure = null,
        public bool $httponly = true,
        string $sameSite = 'Strict',
        public ?string $name = null,
        public ?string $savePath = null,
        public bool $strictMode = true,
        ?int $gcMaxLifetime = null,
    ) {
        $requestedSameSite = $sameSite;
        $sameSite = ucfirst(strtolower($sameSite));
        $secure ??= self::httpsDetected();

        if ($lifetime < 0) {
            throw new InvalidArgumentException(sprintf('Session option "lifetime" must be >= 0, %d given.', $lifetime));
        }

        if ($path === '') {
            throw new InvalidArgumentException('Session option "path" must not be empty.');
        }

        if (!in_array($sameSite, self::SAME_SITE_VALUES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Session option "samesite" must be one of %s, "%s" given.',
                implode(', ', self::SAME_SITE_VALUES),
                $requestedSameSite,
            ));
        }

        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('Session option "samesite" set to "None" requires "secure" to be true.');
        }

        if ($name !== null && (preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1 || ctype_digit($name))) {
            throw new InvalidArgumentException(
                'Session option "name" must contain only letters, digits, "_" or "-", and not be digits only.'
            );
        }

        if ($savePath === '') {
            throw new InvalidArgumentException('Session option "save_path" must not be empty.');
        }

        if ($gcMaxLifetime !== null && $gcMaxLifetime < 1) {
            throw new InvalidArgumentException(sprintf('Session option "gc_maxlifetime" must be >= 1, %d given.', $gcMaxLifetime));
        }

        if ($gcMaxLifetime === null && $lifetime > 0) {
            $gcMaxLifetime = max($lifetime, (int) ini_get('session.gc_maxlifetime'));
        }

        $this->secure = $secure;
        $this->sameSite = $sameSite;
        $this->gcMaxLifetime = $gcMaxLifetime;
    }

    /**
     * @param array<string, mixed> $config Keys: lifetime, path, domain, secure,
     *        httponly, samesite, name, save_path, strict_mode, gc_maxlifetime.
     *        A missing or null value means "use the default".
     *
     * @throws InvalidArgumentException On an unknown option or an invalid value.
     */
    public static function fromArray(array $config): self
    {
        $unknown = array_diff(array_keys($config), array_keys(self::OPTION_TYPES));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown session option(s): %s. Allowed: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::OPTION_TYPES)),
            ));
        }

        // Checked here, with the array key in the message, rather than by
        // catching the constructor's TypeError, which would name the PHP
        // parameter ("$sameSite", "$gcMaxLifetime") instead of the option.
        foreach ($config as $key => $value) {
            if ($value !== null && get_debug_type($value) !== self::OPTION_TYPES[$key]) {
                throw new InvalidArgumentException(sprintf(
                    'Session option "%s" must be of type %s, %s given.',
                    $key,
                    self::OPTION_TYPES[$key],
                    get_debug_type($value),
                ));
            }
        }

        return new self(
            lifetime: $config['lifetime'] ?? 0,
            path: $config['path'] ?? '/',
            domain: $config['domain'] ?? '',
            secure: $config['secure'] ?? null,
            httponly: $config['httponly'] ?? true,
            sameSite: $config['samesite'] ?? 'Strict',
            name: $config['name'] ?? null,
            savePath: $config['save_path'] ?? null,
            strictMode: $config['strict_mode'] ?? true,
            gcMaxLifetime: $config['gc_maxlifetime'] ?? null,
        );
    }

    /**
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: string}
     */
    public function cookieParams(): array
    {
        return [
            'lifetime' => $this->lifetime,
            'path'     => $this->path,
            'domain'   => $this->domain,
            'secure'   => $this->secure,
            'httponly' => $this->httponly,
            'samesite' => $this->sameSite,
        ];
    }

    /**
     * "off" is what some servers (IIS) set for plain HTTP, so the mere presence
     * of the variable is not enough. A non-string value is never HTTPS.
     */
    private static function httpsDetected(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }
}