<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Attributes;

use Attribute;

/**
 * Declares a route or class-level route prefix/configuration using PHP 8+ Attributes.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Route
{
    /** @var list<string> */
    public array $methods;

    /**
     * @param string $path Route path or prefix if applied to a class.
     * @param array<string>|string $methods HTTP methods allowed for this route.
     * @param string|null $name Optional route name or name prefix if applied to a class.
     * @param list<string> $middlewares Middleware class strings.
     * @param array<string, string> $constraints Regex constraints for path parameters.
     */
    public function __construct(
        public string $path = '',
        array|string $methods = ['GET'],
        public ?string $name = null,
        public array $middlewares = [],
        public array $constraints = []
    ) {
        $this->methods = array_map('strtoupper', (array) $methods);
    }
}