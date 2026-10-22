<?php
declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\View\RawHtml;

/**
 * Makes a value safe for a URL attribute (href, src, action...).
 *
 * Any URL carrying a scheme outside the allowlist is replaced by '#'.
 * The result is HTML-escaped and returned as RawHtml so Escaper does not
 * encode it a second time.
 */
final class UrlSanitizer
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function sanitize(mixed $value): RawHtml
    {
        $url = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';

        // Browsers ignore tabs/newlines anywhere in a URL and treat backslashes
        // as slashes in special URLs, so normalise them before testing the scheme.
        $url = trim(preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?? '');
        $url = str_replace('\\', '/', $url);
        $url = preg_replace('/^(https?):\/+/i', '$1://', $url) ?? $url;

        if (
            preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $matches) === 1
            && !in_array(strtolower($matches[1]), self::ALLOWED_SCHEMES, true)
        ) {
            $url = '#';
        }

        return new RawHtml(htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
}