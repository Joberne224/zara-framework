<?php
declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\View\RawHtml;

/**
 * Encodes a value as JSON that is safe to embed in a <script> block, as a
 * JavaScript expression: `var data = {{ data | to_json }};`
 *
 * <, >, &, ' and " inside values are emitted as \uXXXX escapes, so the
 * output can neither close the script element nor open an HTML comment.
 * Encoding failures (invalid UTF-8, unsupported types) throw a JsonException.
 *
 * NOT safe inside an HTML attribute: the structural quotes of the JSON stay
 * raw and would end the attribute. Do not wrap it in quotes either.
 */
final class JsonEncoder
{
    private const FLAGS = JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): RawHtml
    {
        return new RawHtml(json_encode($value, self::FLAGS));
    }
}