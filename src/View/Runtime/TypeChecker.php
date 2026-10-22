<?php

declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\View\Runtime\Exception\AttributeNotFoundException;
use Zara\View\Runtime\Exception\InaccessibleMemberException;
use InvalidArgumentException;

final class TypeChecker
{
    private const SUPPORTED_TESTS = [
        'object',
        'resource',
        'iterable',
        'empty',
        'null',
        'array',
        'string',
        'numeric',
        'scalar',
    ];

    public static function check(\Closure $resolver, string $test): bool
    {
        try {
            $value = $resolver();
        } catch (AttributeNotFoundException | InaccessibleMemberException) {
            // A missing object path or attribute counts as invalid/empty.
            return $test === 'empty';
        }

        return match ($test) {
            'object'   => is_object($value),
            'resource' => is_resource($value),
            'iterable' => is_iterable($value),
            'empty'    => empty($value),
            'null'     => $value === null,
            'array'    => is_array($value),
            'string'   => is_string($value),
            'numeric'  => is_numeric($value),
            'scalar'   => is_scalar($value),
            default    => throw new InvalidArgumentException("Unknown type test '{$test}'."),
        };
    }

    public static function getSupportedTests(): array
    {
        return self::SUPPORTED_TESTS;
    }
}
