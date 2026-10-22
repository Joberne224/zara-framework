<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

use RuntimeException;

/**
 * Generates, stores and validates CSRF tokens.
 *
 * This is the single source of truth for the token and for the names under
 * which it travels (form field and HTTP header), shared by the middleware
 * that verifies requests and by whatever renders forms.
 */
interface CsrfTokenManagerInterface
{
    /**
     * Name of the form field carrying the token.
     */
    public function getFieldName(): string;

    /**
     * Name of the HTTP header carrying the token (AJAX / JSON requests).
     */
    public function getHeaderName(): string;

    /**
     * Returns the current token, creating it on first use.
     *
     * @throws RuntimeException If the session cannot be started or the token cannot be generated.
     */
    public function getToken(): string;

    /**
     * Replaces the current token with a new one and returns it.
     *
     * Call it on every privilege change (login, logout), right after
     * regenerating the session ID.
     *
     * @throws RuntimeException If the session cannot be started or the token cannot be generated.
     */
    public function refresh(): string;

    /**
     * Tells whether the given value matches the current token.
     *
     * Accepts `mixed` on purpose: the value comes straight from the request and
     * may be an array or any other type. Anything but a matching string is
     * simply invalid, never an error.
     *
     * @throws RuntimeException If the session cannot be started.
     */
    public function isValid(mixed $token): bool;

    /**
     * Returns an HTML hidden input carrying the current token.
     *
     * @throws RuntimeException If the session cannot be started or the token cannot be generated.
     */
    public function field(): string;
}