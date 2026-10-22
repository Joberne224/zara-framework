<?php

declare(strict_types=1);

namespace Zara\Core\Http;

use DateTimeInterface;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Zara\Core\Http\Interfaces\ResponseInterface;

/**
 * @phpstan-type HeaderSpec array{original_name: string, value: string}
 * @phpstan-type CookieSpec array{name: string, value: string, expire: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}
 */
class Response implements ResponseInterface
{
    private const CHUNK_SIZE = 8192;

    /** Browsers silently drop a cookie whose name + value exceed this size. */
    private const COOKIE_MAX_BYTES = 4096;

    /** HTTP protocol versions, as written in the PSR-7 convention. */
    private const PROTOCOL_VERSIONS = ['1.0', '1.1', '2', '2.0', '3'];

    private const SAME_SITE_VALUES = ['Strict', 'Lax', 'None'];

    /** Cookie name prefixes (matched case-insensitively, as browsers do). */
    private const SECURE_PREFIX = '__Secure-';
    private const HOST_PREFIX   = '__Host-';

    /** Statuses that must not carry a body (1xx are handled separately). */
    private const BODYLESS_STATUSES = [204, 205, 304];

    /** RFC 7230 token: valid for HTTP header names and cookie names. */
    private const TOKEN_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /** Control characters forbidden in a header value (tab is allowed). Blocks CR/LF header injection. */
    private const HEADER_VALUE_FORBIDDEN_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

    private StreamInterface $body;
    private int             $statusCode;
    private string          $protocolVersion = '1.1';

    /** @var array<string, HeaderSpec> Keyed by lowercase header name. */
    private array           $headers;

    /** @var array<string, CookieSpec> Keyed by domain, path and name (see queueCookie()). */
    private array           $cookies;

    /**
     * @param array<string, string> $headers Header name => value.
     *
     * @throws InvalidArgumentException If the status code or a header is invalid.
     */
    public function __construct(
        string|StreamInterface $body = '',
        int                    $statusCode = HttpStatusCode::OK,
        array                  $headers = []
    ) {
        self::assertValidStatusCode($statusCode);

        $this->body = $body instanceof StreamInterface ? $body : Stream::fromString($body);
        $this->statusCode = $statusCode;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            [$key, $header] = self::normalizeHeader($name, $value);
            $this->headers[$key] = $header;
        }
        $this->cookies = [];
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)]['value'] ?? null;
    }

    public function getHeaders(): array
    {
        $flattened = [];
        foreach ($this->headers as $header) {
            $flattened[$header['original_name']] = $header['value'];
        }
        return $flattened;
    }

    /**
     * Returns the cookies queued for emission.
     *
     * Lets the kernel, middleware and tests inspect what send() will emit
     * without touching PHP's header functions. This is a list, not a map keyed
     * by name: the same name may be queued for several paths or domains.
     *
     * @return list<CookieSpec>
     */
    public function getCookies(): array
    {
        return array_values($this->cookies);
    }

    /**
     * Replaces the body.
     *
     * Any Content-Length header is dropped: it described the previous body.
     */
    public function withBody(string|StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body instanceof StreamInterface ? $body : Stream::fromString($body);
        unset($clone->headers['content-length']);
        return $clone;
    }

    /**
     * @throws InvalidArgumentException If the status code is not between 100 and 599.
     */
    public function withStatusCode(int $statusCode): static
    {
        self::assertValidStatusCode($statusCode);

        $clone = clone $this;
        $clone->statusCode = $statusCode;
        return $clone;
    }

    /**
     * Sets the HTTP protocol version ("1.0", "1.1", "2", "2.0" or "3").
     *
     * Informational only: send() does not emit it, the web server decides the
     * protocol of the status line.
     *
     * @throws InvalidArgumentException If the version is not a known HTTP version.
     */
    public function withProtocolVersion(string $version): static
    {
        if (!in_array($version, self::PROTOCOL_VERSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP protocol version "%s": it must be one of %s.',
                $version,
                implode(', ', self::PROTOCOL_VERSIONS)
            ));
        }

        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    /**
     * Sets a header, replacing any previous value.
     *
     * @throws InvalidArgumentException If the name is not a valid header token, or the
     *         value contains control characters (CR/LF would allow header injection).
     */
    public function withHeader(string $name, string $value): static
    {
        [$key, $header] = self::normalizeHeader($name, $value);

        $clone = clone $this;
        $clone->headers[$key] = $header;
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    /**
     * Queues a cookie, emitted by send(). A cookie with the same name, path and
     * domain replaces the previous one.
     *
     * Everything a browser would silently reject is rejected here instead, at
     * the call site, rather than failing (or vanishing) later in send():
     *  - a name that is not a valid cookie token, or an empty one;
     *  - an empty value: PHP turns it into a deletion header, use withoutCookie();
     *  - a path or domain containing ",", ";", whitespace or control characters;
     *  - a SameSite other than Strict, Lax or None, or None without $secure;
     *  - a "__Secure-" cookie without $secure;
     *  - a "__Host-" cookie without $secure, or with a domain, or a path other than "/";
     *  - a name + value larger than 4096 bytes.
     * Cookie name prefixes are matched case-insensitively, like browsers do.
     *
     * @param int|DateTimeInterface $expire 0 for a session cookie, otherwise an
     *        ABSOLUTE expiry: a Unix timestamp (`time() + 3600`) or a
     *        DateTimeInterface (`new DateTimeImmutable('+1 hour')`). It is not a
     *        duration: `3600` would mean one hour after 1970, an already-expired
     *        cookie. Any non-zero value that is not in the future is therefore
     *        rejected (which catches a duration passed by mistake as well as a
     *        date in the past): use withoutCookie() to delete a cookie.
     * @param string $sameSite "Lax" by default. Case-insensitive.
     *
     * @throws InvalidArgumentException If the cookie would be invalid or rejected by browsers.
     */
    public function withCookie(
        string $name,
        string $value,
        int|DateTimeInterface $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        $timestamp = $expire instanceof DateTimeInterface ? $expire->getTimestamp() : $expire;

        if ($timestamp !== 0 && $timestamp <= time()) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s": expire %d must be 0 (session cookie) or a future Unix timestamp / DateTimeInterface. '
                . 'A value in the past would expire the cookie immediately; small values usually mean a duration '
                . 'in seconds was passed by mistake: pass time() + N or new DateTimeImmutable(\'+N seconds\'), '
                . 'or use withoutCookie() to delete it.',
                $name,
                $timestamp
            ));
        }

        if ($value === '') {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s": an empty value would be emitted by PHP as a deletion header; use withoutCookie() to delete it.',
                $name
            ));
        }

        return $this->queueCookie($name, $value, $timestamp, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    /**
     * Validates a cookie and returns a clone with it queued.
     *
     * Shared by withCookie() and withoutCookie(): the latter legitimately uses
     * a past expiry and an empty value, so those rules live in withCookie() only.
     *
     * @throws InvalidArgumentException If the cookie would be invalid or rejected by browsers.
     */
    private function queueCookie(
        string $name,
        string $value,
        int $expire,
        string $path,
        string $domain,
        bool $secure,
        bool $httpOnly,
        string $sameSite
    ): static {
        $requestedSameSite = $sameSite;
        $sameSite = ucfirst(strtolower($sameSite));

        if (preg_match(self::TOKEN_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid cookie name "%s": it must be a non-empty token (letters, digits and !#$%%&\'*+-.^_`|~).',
                $name
            ));
        }

        if (preg_match('/[\x00-\x20,;\x7f]/', $path) === 1 || $path === '') {
            throw new InvalidArgumentException(sprintf(
                'Invalid cookie path "%s": it must not be empty nor contain ",", ";", whitespace or control characters.',
                $path
            ));
        }

        if (preg_match('/[\x00-\x20,;\x7f]/', $domain) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid cookie domain "%s": it must not contain ",", ";", whitespace or control characters.',
                $domain
            ));
        }

        if (!in_array($sameSite, self::SAME_SITE_VALUES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s": SameSite must be one of %s, "%s" given.',
                $name,
                implode(', ', self::SAME_SITE_VALUES),
                $requestedSameSite
            ));
        }

        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException(sprintf('Cookie "%s": SameSite "None" requires the Secure flag.', $name));
        }

        if (self::hasPrefix($name, self::SECURE_PREFIX) && !$secure) {
            throw new InvalidArgumentException(sprintf('Cookie "%s": the "__Secure-" prefix requires the Secure flag.', $name));
        }

        if (self::hasPrefix($name, self::HOST_PREFIX) && (!$secure || $domain !== '' || $path !== '/')) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s": the "__Host-" prefix requires the Secure flag, no domain and path "/".',
                $name
            ));
        }

        // setcookie() URL-encodes the value, so the encoded size is what is sent.
        if (strlen($name) + strlen(rawurlencode($value)) > self::COOKIE_MAX_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s" is larger than %d bytes: browsers would silently drop it.',
                $name,
                self::COOKIE_MAX_BYTES
            ));
        }

        // The same name on another path or domain is a different cookie for a browser.
        $cookieKey = strtolower(ltrim($domain, '.')) . ';' . $path . ';' . $name;

        $clone = clone $this;
        $clone->cookies[$cookieKey] = [
            'name' => $name,
            'value' => $value,
            'expire' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httpOnly' => $httpOnly,
            'sameSite' => $sameSite,
        ];
        return $clone;
    }

    /**
     * Queues the expiration of a cookie (logout, consent withdrawal...).
     *
     * A browser only deletes a cookie when the path and domain match those it
     * was set with: pass the same $path and $domain as the original withCookie().
     * To delete the same name on several paths or domains, call this once per
     * path/domain pair.
     *
     * The Secure flag is forced on when browsers would reject the deletion
     * without it ("__Secure-" / "__Host-" prefixes, SameSite "None").
     *
     * @throws InvalidArgumentException Under the same rules as withCookie().
     */
    public function withoutCookie(
        string $name,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        $secure = $secure
            || self::hasPrefix($name, self::SECURE_PREFIX)
            || self::hasPrefix($name, self::HOST_PREFIX)
            || strcasecmp($sameSite, 'None') === 0;

        return $this->queueCookie($name, '', 1, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    /**
     * Emits the response in chunks of CHUNK_SIZE bytes rather than echoing the
     * whole content at once: this avoids loading a large body (file, export,
     * scraped content) entirely into memory.
     *
     * No body is emitted for statuses that must not carry one (1xx, 204, 205,
     * 304).
     *
     * If output has already started, the status line, headers and cookies can
     * no longer be sent. Rather than dropping them silently and sending a
     * body with the wrong status, this throws. The CLI SAPI is exempt: it has
     * no HTTP headers at all, so there is nothing to lose and tests that
     * already produced output keep working. For the same reason the CLI never
     * touches output buffers, so tests can capture the body with ob_start().
     *
     * @throws RuntimeException If headers were already sent, or a cookie cannot be emitted.
     */
    public function send(): void
    {
        $headersSent = headers_sent($file, $line);
        $isCli = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);

        if ($headersSent && !$isCli) {
            // The location is only known when the output went through PHP's output
            // layer; after a bare flush() PHP reports an empty file and line 0.
            $where = $file !== '' ? sprintf(' (output started at %s:%d)', $file, $line) : '';

            throw new RuntimeException(
                'Cannot send the response: headers were already sent' . $where . '. '
                . 'Something wrote to the output before the response was sent.'
            );
        }

        if (!$headersSent) {
            http_response_code($this->statusCode);

            foreach ($this->headers as $key => $header) {
                // RFC 9110: a 1xx or 204 response must not carry Content-Length.
                if ($key === 'content-length' && ($this->statusCode < 200 || $this->statusCode === 204)) {
                    continue;
                }
                header($header['original_name'] . ': ' . $header['value']);
            }

            foreach ($this->cookies as $cookie) {
                // The options-array form is the only one supporting SameSite.
                $emitted = setcookie($cookie['name'], $cookie['value'], [
                    'expires'  => $cookie['expire'],
                    'path'     => $cookie['path'],
                    'domain'   => $cookie['domain'],
                    'secure'   => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ]);

                if (!$emitted) {
                    throw new RuntimeException(sprintf('Failed to emit cookie "%s".', $cookie['name']));
                }
            }
        }

        if ($this->statusCode < 200 || in_array($this->statusCode, self::BODYLESS_STATUSES, true)) {
            return;
        }

        if ($this->body->isSeekable()) {
            $this->body->rewind();
        }

        // Outside the CLI, close the output buffers: flush() only reaches the
        // client once nothing is buffered. The loop is bounded by the initial
        // level, so a buffer that cannot be removed cannot make it spin.
        if (!$isCli) {
            for ($level = ob_get_level(); $level > 0; $level--) {
                ob_end_flush();
            }
        }

        while (!$this->body->eof()) {
            $chunk = $this->body->read(self::CHUNK_SIZE);

            // A stream that returns nothing without reporting EOF would loop forever.
            if ($chunk === '') {
                break;
            }

            echo $chunk;

            if (!$isCli) {
                flush();
            }
        }
    }

    private static function hasPrefix(string $name, string $prefix): bool
    {
        return strncasecmp($name, $prefix, strlen($prefix)) === 0;
    }

    /**
     * @throws InvalidArgumentException If the status code is not between 100 and 599.
     */
    private static function assertValidStatusCode(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP status code %d: it must be between 100 and 599.',
                $statusCode
            ));
        }
    }

    /**
     * Validates a header and returns it as [lowercase key, stored entry].
     *
     * Takes mixed types on purpose: it also validates the raw array given to
     * the constructor, where a key can be an int (list-style array) and a
     * value anything. strtolower() on an int key would otherwise raise a
     * TypeError, and an array value would only fail later, in send().
     *
     * @return array{0: string, 1: HeaderSpec}
     *
     * @throws InvalidArgumentException If the name or value is not a valid header name/value.
     */
    private static function normalizeHeader(mixed $name, mixed $value): array
    {
        if (!is_string($name) || preg_match(self::TOKEN_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid header name %s: it must be a non-empty string made of letters, digits and !#$%%&\'*+-.^_`|~ '
                . '(a list-style array such as ["X-Foo: bar"] is not accepted: use ["X-Foo" => "bar"]).',
                is_string($name) ? '"' . $name . '"' : var_export($name, true)
            ));
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value for header "%s": a string is expected, %s given.',
                $name,
                get_debug_type($value)
            ));
        }

        if (preg_match(self::HEADER_VALUE_FORBIDDEN_PATTERN, $value) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value for header "%s": it contains control characters (CR/LF would allow header injection).',
                $name
            ));
        }

        return [strtolower($name), ['original_name' => $name, 'value' => $value]];
    }
}