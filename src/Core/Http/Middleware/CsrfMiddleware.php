<?php
declare(strict_types=1);

namespace Zara\Core\Http\Middleware;

use InvalidArgumentException;
use Zara\Core\Error\HttpException\ForbiddenException;
use Zara\Core\Http\Interfaces\CsrfTokenManagerInterface;
use Zara\Core\Http\Interfaces\MethodInterface;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\Interfaces\MiddlewareInterface;

/**
 * CSRF protection middleware.
 *
 * Verifies the token of every unsafe request (anything but GET, HEAD and
 * OPTIONS). Token generation and storage are delegated to the
 * CsrfTokenManagerInterface; this class only decides which requests are
 * checked and where the submitted token is read from.
 *
 * On failure it throws a ForbiddenException (403) instead of returning a raw
 * Response: the exception bubbles up to Kernel -> ErrorHandler (PSR-3 log,
 * dev/prod rendering, HTTP 403).
 *
 * Safe requests and excluded paths never touch the session.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private CsrfTokenManagerInterface $tokens;

    /** @var list<string> */
    private array $excludedPaths;

    /**
     * @param list<string> $excludedPaths Paths exempt from verification, e.g. stateless
     *        API endpoints authenticated by a bearer token. An entry is either an exact
     *        path ('/webhooks/stripe') or a prefix ending in '/*' ('/api/*').
     *
     * @throws InvalidArgumentException If an excluded path is not a string starting with '/'.
     */
    public function __construct(CsrfTokenManagerInterface $tokens, array $excludedPaths = [])
    {
        foreach ($excludedPaths as $path) {
            if (!is_string($path) || !str_starts_with($path, '/')) {
                throw new InvalidArgumentException(
                    'Each excluded CSRF path must be a string starting with "/".'
                );
            }
        }

        $this->tokens = $tokens;
        $this->excludedPaths = array_values($excludedPaths);
    }

    /**
     * @throws ForbiddenException If the CSRF token is missing or invalid.
     */
    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        if ($this->isSafeMethod($request->getMethod()) || $this->isExcluded($request)) {
            return $next($request);
        }

        if (!$this->tokens->isValid($this->extractToken($request))) {
            throw new ForbiddenException('Invalid or missing CSRF token.');
        }

        return $next($request);
    }

    /**
     * Reads the submitted token: form field first, then HTTP header.
     *
     * The query string is deliberately NOT a source: tokens in URLs leak
     * through server logs, browser history and the Referer header.
     *
     * Returns the raw value only when it is a non-empty string; any other
     * shape (array, missing) is reported as null and fails validation.
     */
    private function extractToken(RequestInterface $request): ?string
    {
        $fromBody = $request->getPostParams()->get($this->tokens->getFieldName());

        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        $fromHeader = $request->getHeaders()->get($this->tokens->getHeaderName());

        if (is_array($fromHeader)) {
            $fromHeader = $fromHeader[0] ?? null;
        }

        return is_string($fromHeader) && $fromHeader !== '' ? $fromHeader : null;
    }

    private function isSafeMethod(MethodInterface $method): bool
    {
        return in_array(strtoupper($method->getName()), self::SAFE_METHODS, true);
    }

    private function isExcluded(RequestInterface $request): bool
    {
        if ($this->excludedPaths === []) {
            return false;
        }

        $path = $request->getUri()->getPath();

        foreach ($this->excludedPaths as $excluded) {
            if (str_ends_with($excluded, '/*')) {
                // '/api/*' excludes everything under '/api/' (prefix keeps the trailing slash).
                if (str_starts_with($path, substr($excluded, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($path === $excluded) {
                return true;
            }
        }

        return false;
    }
}