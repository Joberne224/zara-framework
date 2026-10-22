<?php
declare(strict_types=1);

namespace Zara\Core\Http\Security;

use Zara\Core\Http\Interfaces\CsrfTokenManagerInterface;
use Zara\Core\Http\Interfaces\SessionInterface;

/**
 * Session-backed CSRF token manager (synchronizer token pattern).
 *
 * The token is created lazily: nothing touches the session until a token is
 * actually requested (to render a form) or checked (on an unsafe request), so
 * plain GET traffic never creates a session or sets a cookie.
 *
 * The SessionInterface injected here must be the same shared instance as the
 * one carried by the Request, otherwise the token would be written to one
 * session object and checked against another.
 */
final class CsrfTokenManager implements CsrfTokenManagerInterface
{
    private const SESSION_KEY = '_csrf_token';
    private const FIELD_NAME = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-TOKEN';
    private const TOKEN_BYTES = 32;

    private SessionInterface $session;

    public function __construct(SessionInterface $session)
    {
        $this->session = $session;
    }

    public function getFieldName(): string
    {
        return self::FIELD_NAME;
    }

    public function getHeaderName(): string
    {
        return self::HEADER_NAME;
    }

    public function getToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->refresh();
    }

    public function refresh(): string
    {
        // random_bytes() throws if no secure source of randomness is
        // available: the failure is loud, never a weak token.
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function isValid(mixed $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $expected = $this->session->get(self::SESSION_KEY);

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::FIELD_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->getToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}