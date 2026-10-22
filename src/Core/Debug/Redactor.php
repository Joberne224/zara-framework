<?php
declare(strict_types=1);

namespace Zara\Core\Debug;

/**
 * Decides which array keys and property names must never be dumped
 * (session ids, passwords, tokens...). The single place to edit the rule.
 */
final class Redactor
{
    /** Shown in place of a sensitive value in the CLI and JSON outputs. */
    public const MASK = '[redacted]';

    /** Case-insensitive substrings: "token" also matches "csrf_token" and "access_token". */
    private const SENSITIVE_NAMES = [
        'sessid', 'session_id', 'password', 'passwd', 'pwd', 'pass', 'secret', 'token',
        'csrf', 'jwt', 'authorization', 'cookie', 'api_key', 'api-key', 'apikey',
        'private_key', 'privatekey', 'app_key', 'app-key', 'encryption_key',
        'signing_key', 'access_key', 'client_key', 'secret_key', 'credential', 'signature',
    ];

    public static function isSensitive(int|string $name): bool
    {
        if (is_int($name)) {
            return false;
        }

        foreach (self::SENSITIVE_NAMES as $needle) {
            if (stripos($name, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
